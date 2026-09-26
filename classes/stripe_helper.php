<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Various helper methods for interacting with the Stripe API
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

use core_payment\helper;
use core_payment\local\entities\payable;
use core_user;
use DateInterval;
use DateTime;
use DateTimeZone;
use moodle_url;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

/**
 * The helper class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stripe_helper {

    /**
     * @var StripeClient Secret API key (Do not publish).
     */
    private $stripe;
    /**
     * @var string Public API key.
     */
    private $apikey;

    /**
     * @var string Stripe API version set explicitly in Stripe client.
     */
    public static $apiversion = '2023-08-16';

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     */
    public function __construct(string $apikey, string $secretkey) {
        $this->apikey = $apikey;
        $this->stripe = new StripeClient([
                'api_key' => $secretkey,
                'stripe_version' => self::$apiversion,
        ]);
        Stripe::setAppInfo(
                'Moodle Stripe Payment Gateway',
                get_config('paygw_stripe')->version,
                'https://github.com/alexmorrisnz/moodle-paygw_stripe'
        );
    }

    /**
     * Find a product in the database and the corresponding Stripe Product item.
     *
     * Returns null when the stored product is archived (active = false) in Stripe. An archived
     * product cannot receive new prices and cannot be used as a Checkout line item, so we treat
     * it as missing and drop the stale mapping, letting the caller create a fresh product.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product|null
     * @throws \dml_exception
     */
    public function get_product(string $component, string $paymentarea, string $itemid): ?Product {
        global $DB;

        if ($record = $DB->get_record('paygw_stripe_products',
                ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid])) {
            try {
                $product = $this->stripe->products->retrieve($record->productid);
                // An archived (inactive) product cannot receive new prices and cannot be used in a
                // Checkout Session. Treat it as missing: delete the stale mapping so the caller
                // creates a brand new active product instead of failing the payment.
                if (empty($product->active)) {
                    $DB->delete_records('paygw_stripe_products',
                            ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid]);
                    return null;
                }
                return $product;
            } catch (ApiErrorException $e) {
                // Product exists in Moodle but not in stripe, possibly the keys were switched.
                // Delete product for creation later.
                $DB->delete_records('paygw_stripe_products',
                        ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid]);
                return null;
            }
        }
        return null;
    }

    /**
     * Create a product in Stripe and save the ID into the Moodle database.
     *
     * @param string $description
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_product(string $description, string $component, string $paymentarea, string $itemid): Product {
        global $DB;
        $product = $this->stripe->products->create([
                'name' => $description,
                'metadata' => $this->build_product_metadata($component, $paymentarea, $itemid),
        ]);
        $record = new \stdClass();
        $record->productid = $product->id;
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $DB->insert_record('paygw_stripe_products', $record);
        return $product;
    }

    /**
     * Resolve the enrolment instance a payment item belongs to.
     *
     * For enrolment plugins that sell access through core_payment (enrol_fee and its forks,
     * e.g. enrol_feestripe) the itemid is an enrol instance id (enrol.id), not a course id.
     * The plugin name is derived from the component instead of being hard-coded, and the
     * fetched instance must be of that very plugin, so an itemid of another component (or a
     * stale/reused one) never resolves to an unrelated course.
     *
     * @param string $component e.g. enrol_fee, enrol_feestripe
     * @param int|string $itemid
     * @return \stdClass|null The mdl_enrol record, or null when the item is not an enrol instance.
     */
    public static function resolve_enrol_instance(string $component, $itemid): ?\stdClass {
        global $DB;

        if (strpos($component, 'enrol_') !== 0 || empty($itemid)) {
            return null;
        }
        $enroltype = substr($component, strlen('enrol_'));
        $enrol = $DB->get_record('enrol', ['id' => $itemid]);
        if (!$enrol || $enrol->enrol !== $enroltype) {
            return null;
        }
        return $enrol;
    }

    /**
     * Build the name of the Stripe product (and the payment description) for a payment item.
     *
     * The name is built on the server and never taken from the request: the description
     * passed to pay.php is part of the URL (user-editable) and localised in the buyer's
     * language, which used to rename the product whenever someone bought it with a different
     * interface language. The name shows up on the Checkout page, the invoice line and in
     * reports, so it has to be stable.
     *
     * For courses: "Kurs online: <course full name>", or "Online course: <course full name>"
     * when the course forces a language other than Polish. The full name is formatted in the
     * forced course language (or the site default language), not in the current user's.
     * Items that are not courses fall back to the normalised description.
     *
     * @param string $component
     * @param string $itemid
     * @param string $fallback Description to use when the item is not a course.
     * @return string
     */
    public function build_product_name(string $component, string $itemid, string $fallback): string {
        global $CFG, $DB;

        if (($enrol = self::resolve_enrol_instance($component, $itemid))
                && ($course = $DB->get_record('course', ['id' => $enrol->courseid]))) {
            $courselang = trim((string) $course->lang);
            $polish = $courselang === '' || $courselang === 'pl' || strpos($courselang, 'pl_') === 0;
            $prefix = $polish ? 'Kurs online: ' : 'Online course: ';

            // Format (e.g. multilang filter) in a fixed language, independent of the buyer.
            $previous = force_current_language($courselang !== '' ? $courselang : ($CFG->lang ?? ''));
            try {
                $fullname = format_string($course->fullname, true,
                        ['context' => \context_course::instance($course->id)]);
            } finally {
                force_current_language($previous);
            }
            return mb_substr($prefix . trim($fullname), 0, 250);
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $fallback)), 0, 250);
    }

    /**
     * Build the Stripe metadata describing the Moodle item a product represents.
     *
     * Keys are prefixed with "moodle_" to avoid collisions with metadata set elsewhere
     * (e.g. manually in the Dashboard). The method is component-aware: for enrolment plugins
     * (any enrol_* component, see resolve_enrol_instance()) the itemid is an enrol instance id,
     * so we resolve the owning course and enrolment method.
     * For any other component only the generic technical keys are returned. Empty values
     * are omitted so we never push blank metadata, and every value is clamped to Stripe's
     * 500-character limit. The set of keys is intentionally well below Stripe's 50-key cap.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return array<string, string>
     */
    private function build_product_metadata(string $component, string $paymentarea, string $itemid): array {
        global $CFG, $DB;

        $metadata = [
                'moodle_component' => $component,
                'moodle_paymentarea' => $paymentarea,
                'moodle_site' => $CFG->wwwroot,
        ];

        // For enrolment plugins the itemid is an enrol instance id, not a course id.
        $resolved = false;
        if ($enrol = self::resolve_enrol_instance($component, $itemid)) {
            $resolved = true;
            // Kind of item sold. Only courses exist today; other product types (e.g. bundles,
            // all-access plans) will set their own value.
            $metadata['moodle_product_type'] = 'course';
            $metadata['moodle_enrol_id'] = (string) $enrol->id;

            // get_instance_name() returns a human label even when enrol.name is empty
            // (it falls back to the plugin's default name), so it is never blank.
            if ($enrolplugin = enrol_get_plugin($enrol->enrol)) {
                $enrolname = trim((string) $enrolplugin->get_instance_name($enrol));
                if ($enrolname !== '') {
                    $metadata['moodle_enrol_name'] = $enrolname;
                }
            }

            if ($course = $DB->get_record('course', ['id' => $enrol->courseid])) {
                // Pass the course context explicitly to format_string(): this runs from pay.php,
                // which does not set $PAGE->context, and format_string() would otherwise emit a
                // "$PAGE->context was not set" debugging notice while falling back to it.
                $coursecontext = \context_course::instance($course->id);
                $metadata['moodle_course_id'] = (string) $course->id;
                if (trim((string) $course->idnumber) !== '') {
                    $metadata['moodle_course_idnumber'] = (string) $course->idnumber;
                }
                if (trim((string) $course->fullname) !== '') {
                    $metadata['moodle_course_fullname'] = format_string($course->fullname, true,
                            ['context' => $coursecontext]);
                }
                if (trim((string) $course->shortname) !== '') {
                    $metadata['moodle_course_shortname'] = format_string($course->shortname, true,
                            ['context' => $coursecontext]);
                }
                $metadata['moodle_course_url'] =
                        (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            }
        }

        // Always keep a generic identifier when we could not resolve an enrol instance
        // (unknown component, or a stale/deleted enrol row).
        if (!$resolved) {
            $metadata['moodle_itemid'] = (string) $itemid;
        }

        foreach ($metadata as $key => $value) {
            $metadata[$key] = mb_substr((string) $value, 0, 500);
        }

        return $metadata;
    }

    /**
     * Whether a Stripe product's existing metadata is missing any of the desired keys or
     * holds a different value for one of them. Used to keep metadata updates idempotent so
     * we do not issue a needless products->update on every payment. Extra keys already on the
     * product (e.g. set manually in the Dashboard) are ignored and preserved by Stripe's merge.
     *
     * @param mixed $existing Stripe metadata (StripeObject, array or null)
     * @param array $desired Desired moodle_* metadata
     * @return bool
     */
    private function metadata_needs_update($existing, array $desired): bool {
        if ($existing === null) {
            $existingarr = [];
        } else if (is_array($existing)) {
            $existingarr = $existing;
        } else {
            // \Stripe\StripeObject.
            $existingarr = $existing->toArray();
        }
        foreach ($desired as $key => $value) {
            if (!array_key_exists($key, $existingarr) || (string) $existingarr[$key] !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the metadata attached to a payment: the Checkout Session, the payment intent, the
     * subscription and the generated invoice. It describes both the course being bought and the
     * Moodle user buying it, so a payment can be identified in the Stripe Dashboard without a
     * lookup in the Moodle database.
     *
     * The first block holds the historical, unprefixed keys. webhook.php and
     * process_stripe_event() read component/paymentarea/itemid from them and payments made
     * before this change carry only those, so they are deliberately kept as they are; the
     * moodle_* keys are added alongside them, never instead of them.
     *
     * @param \stdClass $user
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return array<string, string>
     */
    private function build_payment_metadata($user, string $component, string $paymentarea, string $itemid): array {
        return array_merge([
                'userid' => $user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
        ], $this->build_product_metadata($component, $paymentarea, $itemid),
                $this->build_customer_metadata($user));
    }

    /**
     * Get the first price listed on a product.
     *
     * @param Product $product
     * @param bool $subscription
     * @return Price|null
     */
    public function get_price(Product $product, bool $subscription = false): ?Price {
        try {
            $prices = $this->stripe->prices->all(['product' => $product->id]);
            foreach ($prices as $price) {
                if ($price instanceof Price) {
                    if ($price->active) {
                        if ($subscription && $price->type == 'recurring') {
                            return $price;
                        } else if (!$subscription) {
                            return $price;
                        }
                    }
                }
            }
            return null;
        } catch (ApiErrorException $e) {
            return null;
        }
    }

    /**
     * Create a price against an associated product.
     *
     * @param string $currency Currency
     * @param string $productid Product ID
     * @param float $unitamount Price
     * @param bool $automatictax Toggles insertion of a tax behavior
     * @param string|null $defaultbehavior The default tax behavior for the price, if enabled
     * @param array|null $recurring
     * @return Price
     * @throws ApiErrorException
     */
    public function create_price(string $currency, string $productid, float $unitamount, bool $automatictax,
            ?string $defaultbehavior, array $recurring = null) {
        $pricedata = [
                'currency' => $currency,
                'product' => $productid,
                'unit_amount' => $unitamount,
        ];
        if ($automatictax == 1) {
            $pricedata['tax_behavior'] = $defaultbehavior ?? 'inclusive';
        }
        if (is_array($recurring)) {
            $pricedata['recurring'] = $recurring;
        }
        return $this->stripe->prices->create($pricedata);
    }

    /**
     * Whether Stripe Tax (automatic_tax) should be enabled for this gateway config.
     *
     * The gateway uses a single "taxmode" selector (none|automatic|manual). For backward
     * compatibility, configs saved before that selector existed only have the legacy
     * "enableautomatictax" checkbox, which we still honour here.
     *
     * @param object $config Gateway configuration
     * @return bool
     */
    private function is_automatic_tax(object $config): bool {
        if (isset($config->taxmode)) {
            return $config->taxmode === 'automatic';
        }
        return !empty($config->enableautomatictax);
    }

    /**
     * Return the manual Stripe Tax Rate ID (txr_...) to apply to line items, or null.
     *
     * Only returns a value in "manual" tax mode. Manual tax rates and automatic_tax are
     * mutually exclusive in Stripe Checkout, so callers must not enable both.
     *
     * @param object $config Gateway configuration
     * @return string|null
     */
    private function get_manual_tax_rate(object $config): ?string {
        if (($config->taxmode ?? '') !== 'manual') {
            return null;
        }
        $rate = trim($config->manualtaxrate ?? '');
        return $rate !== '' ? $rate : null;
    }

    /**
     * Get the stripe Customer object from the corresponding Moodle user id.
     *
     * @param int $userid
     * @return Customer|null
     * @throws \dml_exception
     */
    public function get_customer(int $userid): ?Customer {
        global $DB;
        if (!$record = $DB->get_record('paygw_stripe_customers', ['userid' => $userid])) {
            return null;
        }
        try {
            return $this->stripe->customers->retrieve($record->customerid);
        } catch (ApiErrorException $e) {
            // Customer exists in Moodle but not in stripe, possibly the keys were switched.
            // Delete customer for creation later.
            $DB->delete_records('paygw_stripe_customers', ['userid' => $userid]);
            return null;
        }
    }

    /**
     * Create a Stripe customer object and save the ID and user ID into the database.
     *
     * @param \stdClass $user
     * @return Customer
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function create_customer($user): Customer {
        global $DB;
        $customer = $this->stripe->customers->create([
                'email' => $user->email,
                'description' => get_string('customerdescription', 'paygw_stripe', $user->id),
                'metadata' => $this->build_customer_metadata($user),
        ]);
        $record = new \stdClass();
        $record->userid = $user->id;
        $record->customerid = $customer->id;
        $DB->insert_record('paygw_stripe_customers', $record);
        return $customer;
    }

    /**
     * Build the Stripe metadata describing the Moodle user a customer represents.
     *
     * Keys are prefixed with "moodle_" to avoid collisions with metadata set elsewhere.
     * Only identifying/reconciliation data is included (not billing details, which Stripe
     * collects and stores as structured Customer fields). Conditional fields are omitted when
     * empty and every value is clamped to Stripe's 500-character limit; the key count stays
     * well below Stripe's 50-key cap.
     *
     * @param \stdClass $user
     * @return array<string, string>
     */
    private function build_customer_metadata($user): array {
        global $CFG;

        $metadata = [
                'moodle_user_id' => (string) $user->id,
                'moodle_username' => (string) ($user->username ?? ''),
                'moodle_site' => $CFG->wwwroot,
        ];

        $fullname = trim(fullname($user));
        if ($fullname !== '') {
            $metadata['moodle_user_fullname'] = $fullname;
        }

        // Institutional identifiers, only when the Moodle profile actually holds them.
        $optional = [
                'idnumber' => 'moodle_user_idnumber',
                'institution' => 'moodle_institution',
                'department' => 'moodle_department',
        ];
        foreach ($optional as $field => $key) {
            if (isset($user->$field) && trim((string) $user->$field) !== '') {
                $metadata[$key] = (string) $user->$field;
            }
        }

        foreach ($metadata as $key => $value) {
            $metadata[$key] = mb_substr((string) $value, 0, 500);
        }

        return $metadata;
    }

    /**
     * Backfill/refresh the moodle_* metadata on a Stripe customer, idempotently. Customers
     * created before this metadata existed, or whose Moodle profile changed, are updated
     * lazily on their next payment. The update is skipped when nothing is missing or stale.
     *
     * @param Customer $customer
     * @param \stdClass $user
     * @return Customer
     * @throws ApiErrorException
     */
    private function sync_customer_metadata(Customer $customer, $user): Customer {
        $desired = $this->build_customer_metadata($user);
        if ($this->metadata_needs_update($customer->metadata ?? null, $desired)) {
            $customer = $this->stripe->customers->update($customer->id, ['metadata' => $desired]);
        }
        return $customer;
    }

    /**
     * Creates Stripe product and price objects together.
     * Stores object IDs in Moodle to prevent creating duplicates.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @param array|null $subscription
     * @return array
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    private function create_product_and_price(object $config, payable $payable, string $description, float $cost, string $component,
            string $paymentarea, string $itemid, array $subscription = null) {
        $unitamount = $this->get_unit_amount($cost, $payable->get_currency());
        $currency = strtolower($payable->get_currency());
        // The product name is built on the server; the request description is only a fallback.
        $description = $this->build_product_name($component, $itemid, $description);

        if (!$product = $this->get_product($component, $paymentarea, $itemid)) {
            $product = $this->create_product($description, $component, $paymentarea, $itemid);
        }
        if (!$price = $this->get_price($product, is_array($subscription))) {
            $price = $this->create_price($currency, $product->id, $unitamount, $this->is_automatic_tax($config),
                    $config->defaulttaxbehavior, $subscription);
        } else {
            // Check if the price details mismatch in any way.
            if ($price->unit_amount != $unitamount || $price->currency != $currency ||
                    (is_array($subscription) && $price->type != 'recurring') ||
                    (is_array($subscription) && $price->type == 'recurring' &&
                            ($price->recurring->toArray()['interval'] != $subscription['interval'] ||
                                    $price->recurring->toArray()['interval_count'] != $subscription['interval_count'])) ||
                    ($price->type == 'recurring' && !is_array($subscription))) {
                // We cannot update the price or currency, so we must create a new price.
                $this->stripe->prices->update($price->id, ['active' => false]);
                $price = $this->create_price($currency, $product->id, $unitamount, $this->is_automatic_tax($config),
                        $config->defaulttaxbehavior, $subscription);
            }
            // Set tax behavior if not set already.
            if ($this->is_automatic_tax($config) && (!isset($price->tax_behavior) || $price->tax_behavior === 'unspecified')) {
                $price->updateAttributes(['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
                $price = $this->stripe->prices->update($price->id, ['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
            }
        }
        if ($product->name != $description) {
            $product->name = $description;
            $product = $this->stripe->products->update($product->id, ['name' => $description]);
        }

        // Backfill/refresh metadata on the product. Products created before this feature
        // existed (or whose course details changed) are updated lazily on the next payment.
        // The update is skipped unless something is actually missing or stale, so we do not
        // issue a redundant API call on every checkout.
        $desiredmetadata = $this->build_product_metadata($component, $paymentarea, $itemid);
        // moodle_itemid is only set when the course could not be resolved. Stripe merges metadata
        // on update, so once the course resolves the stale key has to be removed explicitly
        // (an empty value deletes a key). Only sent when present, otherwise every checkout would
        // see it as "missing" and update the product again.
        $existingmetadata = isset($product->metadata) ? $product->metadata->toArray() : [];
        if (!isset($desiredmetadata['moodle_itemid']) && array_key_exists('moodle_itemid', $existingmetadata)) {
            $desiredmetadata['moodle_itemid'] = '';
        }
        if ($this->metadata_needs_update($product->metadata ?? null, $desiredmetadata)) {
            $product = $this->stripe->products->update($product->id, ['metadata' => $desiredmetadata]);
        }

        return [$product, $price];
    }

    /**
     * Create a payment intent and return with the checkout session id.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string
     * @throws ApiErrorException
     */
    public function generate_payment(object $config, payable $payable, string $description, float $cost, string $component,
            string $paymentarea, string $itemid): string {
        global $CFG, $USER;

        // Ensure webhook exists before we potentially use it.
        $this->create_webhook($payable->get_account_id());

        list($product, $price) = $this->create_product_and_price($config, $payable, $description, $cost, $component,
                $paymentarea, $itemid);

        if (!$customer = $this->get_customer($USER->id)) {
            $customer = $this->create_customer($USER);
        }
        // Ensure an existing customer carries up-to-date moodle_* metadata (idempotent).
        $customer = $this->sync_customer_metadata($customer, $USER);

        // Build the line item, attaching a manual Stripe Tax Rate in manual tax mode. Manual
        // tax_rates and automatic_tax are mutually exclusive in Stripe, so get_manual_tax_rate()
        // only returns a value when automatic tax is off.
        $lineitem = [
                'price' => $price,
                'quantity' => 1,
        ];
        if ($taxrate = $this->get_manual_tax_rate($config)) {
            $lineitem['tax_rates'] = [$taxrate];
        }

        // Describe the course and the buyer on the payment, so the Checkout Session, the
        // payment_intent and the resulting invoice can all be read in Stripe without a lookup
        // in the Moodle database.
        $sessionmetadata = $this->build_payment_metadata($USER, $component, $paymentarea, $itemid);

        // Label for the payments list: the same name as the product (see build_product_name()).
        $paymentlabel = $this->build_product_name($component, $itemid, $description);

        $params = [
                'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid,
                'payment_method_options' => [
                        'wechat_pay' => [
                                'client' => "web"
                        ],
                ],
                'invoice_creation' => [
                        'enabled' => true,
                        // An invoice inherits no metadata from the payment that generated it,
                        // so the same set has to be attached explicitly.
                        'invoice_data' => [
                                'metadata' => $sessionmetadata,
                        ],
                ],
                'mode' => 'payment',
                'line_items' => [$lineitem],
                'automatic_tax' => [
                        'enabled' => $this->is_automatic_tax($config),
                ],
                'customer' => $customer->id,
                'metadata' => $sessionmetadata,
                'payment_intent_data' => [
                        'metadata' => $sessionmetadata,
                        // Fills the "Description" column of the Stripe payments list, so the
                        // course is visible without opening the payment.
                        'description' => $paymentlabel,
                ],
                'allow_promotion_codes' => $config->allowpromotioncodes == 1,
                'customer_update' => [
                        'name' => 'auto',
                        'address' => 'auto',
                ],
                'billing_address_collection' => 'required',
                'tax_id_collection' => [
                        'enabled' => true
                ],

        ];

        // When dynamic payment methods are disabled, restrict the session to the manually
        // selected methods. When enabled, the parameter is omitted so Stripe uses the methods
        // configured in the Dashboard (dynamic payment methods), filtered by currency and amount.
        if (empty($config->usedynamicpaymentmethods)) {
            $params['payment_method_types'] = $config->paymentmethods;
        }

        $session = $this->stripe->checkout->sessions->create($params);

        return $session->id;
    }

    /**
     * Create a subscription to the course and return with checkout session id.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string|null
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function generate_subscription(object $config, payable $payable, string $description, float $cost, string $component,
            string $paymentarea, string $itemid): ?string {
        global $CFG, $USER, $DB;

        // Ensure webhook exists before we use it.
        $this->create_webhook($payable->get_account_id());

        $pricedetails = $this->get_subscription_config_price_details($config);

        list($product, $price) = $this->create_product_and_price($config, $payable, $description, $cost, $component,
                $paymentarea, $itemid, $pricedetails);

        if (!$customer = $this->get_customer($USER->id)) {
            $customer = $this->create_customer($USER);
        }
        // Ensure an existing customer carries up-to-date moodle_* metadata (idempotent).
        $customer = $this->sync_customer_metadata($customer, $USER);

        // If anchored billing and/or trial period are enabled, set up the subscriptiondata parameter.
        $subscriptiondata = [];
        if ($config->anchorbilling) {
            $subscriptiondata['billing_cycle_anchor'] = $this->get_anchor_billing_dates($config)->getTimestamp();
            if ($config->firstintervalfree) {
                $subscriptiondata['proration_behavior'] = 'none';
            }
        }
        if ($config->firstintervalfree && !$config->anchorbilling) {
            $subscriptiondata['trial_end'] = $this->get_trial_end_date($config)->getTimestamp();
        }

        // In manual tax mode, apply the Stripe Tax Rate to the subscription. For recurring
        // payments Stripe uses subscription_data.default_tax_rates (line_items.tax_rates is only
        // for one-time payments). Manual tax rates and automatic_tax remain mutually exclusive, so
        // get_manual_tax_rate() only returns a value when automatic tax is off.
        $lineitem = [
                'price' => $price,
                'quantity' => 1,
        ];
        if ($taxrate = $this->get_manual_tax_rate($config)) {
            $subscriptiondata['default_tax_rates'] = [$taxrate];
        }

        // Describe the course and the buyer on the checkout session and on the subscription
        // itself. Recurring invoices do not copy the subscription's metadata into their own
        // metadata; Stripe exposes it on each invoice as subscription_details.metadata, so
        // renewals can still be tied to the course and the buyer.
        $sessionmetadata = $this->build_payment_metadata($USER, $component, $paymentarea, $itemid);
        $subscriptiondata['metadata'] = array_merge($subscriptiondata['metadata'] ?? [], $sessionmetadata);

        // Create checkout session to set up subscription for customer.
        $params = [
                'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid,
                // Note: invoice_creation is only valid for mode=payment. For mode=subscription,
                // Stripe generates an invoice automatically for every billing cycle, so this
                // parameter must be omitted here (it caused InvalidRequestException otherwise).
                'mode' => 'subscription',
                'line_items' => [$lineitem],
                'automatic_tax' => [
                        'enabled' => $this->is_automatic_tax($config),
                ],
                'allow_promotion_codes' => $config->allowpromotioncodes == 1,
                'subscription_data' => $subscriptiondata,
                'customer' => $customer->id,
                'metadata' => $sessionmetadata,
                'customer_update' => [
                        'name' => 'auto',
                        'address' => 'auto',
                ],
                'billing_address_collection' => 'required',
                'tax_id_collection' => [
                        'enabled' => true
                ]
        ];

        // See generate_payment(): omit payment_method_types to use dynamic payment methods.
        if (empty($config->usedynamicpaymentmethods)) {
            $params['payment_method_types'] = $config->paymentmethods;
        }

        $session = $this->stripe->checkout->sessions->create($params);

        return $session->id;
    }

    /**
     * Retrieve the Checkout Session mode
     *
     * @param string $sessionid Stripe session ID
     * @return string
     * @throws ApiErrorException
     */
    public function get_sessionmode(string $sessionid): string {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->mode;
    }

    /**
     * Check if a checkout session has been paid
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_paid(string $sessionid): bool {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->payment_status === 'paid';
    }

    /**
     * Check if a checkout session is pending payment.
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_pending(string $sessionid): bool {
        // Check payment intent here as the session status is a simple pass/fail that doesn't include processing.
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['payment_intent']]);
        return $session->payment_intent->status === 'processing';
    }

    /**
     * Check the status of a Stripe subscription
     *
     * @param string $sessionid Stripe session ID
     * @return string
     * @throws ApiErrorException
     */
    public function get_subscription_status(string $sessionid): string {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);
        return $subscription->status;
    }

    /**
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array(strtoupper($currency), gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }

    /**
     * Get localised string of a cost
     *
     * @param float $cost
     * @param string $currency
     * @return string
     */
    public function get_localised_cost(float $cost, string $currency): string {
        if (!in_array(strtoupper($currency), gateway::get_zero_decimal_currencies())) {
            $cost = $cost / 100;
        }

        $locale = get_string('localecldr', 'langconfig');
        $fmt = \NumberFormatter::create($locale, \NumberFormatter::CURRENCY);
        return numfmt_format_currency($fmt, $cost, $currency);
    }

    /**
     * Retrieve Stipe subscription details and save in Moodle based on Stripe checkout session.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    private function save_subscription(Session $session) {
        global $DB, $USER;

        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);

        $datum = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $session->subscription]);
        if ($datum != null) {
            $datum->status = $subscription->status;
            $DB->update_record('paygw_stripe_subscriptions', $datum);
            return;
        }

        $datum = new \stdClass();
        $datum->userid = $USER->id;
        $datum->subscriptionid = $session->subscription;
        $datum->customerid = $session->customer->id;
        $datum->status = $subscription->status;
        $datum->productid = $session->line_items->first()->price->product;
        $datum->priceid = $session->line_items->first()->price->id;

        $DB->insert_record('paygw_stripe_subscriptions', $datum);
    }

    /**
     * Save payment intent status with customer and product details.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    private function save_payment_intent(Session $session) {
        global $DB, $USER;

        $intent = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent]);
        if ($intent != null) {
            $intent->status = $session->status;
            $intent->paymentstatus = $session->payment_status;
            $DB->update_record('paygw_stripe_intents', $intent);
            return;
        }

        $intent = new \stdClass();
        $intent->userid = $USER->id;
        $intent->paymentintent = $session->payment_intent;
        $intent->customerid = $session->customer->id;
        $intent->amounttotal = $session->amount_total;
        $intent->paymentstatus = $session->payment_status;
        $intent->status = $session->status;
        $intent->productid = $session->line_items->first()->price->product;

        $DB->insert_record('paygw_stripe_intents', $intent);
    }

    /**
     * Saves the payment status
     *
     * @param string $sessionid
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function save_payment_status(string $sessionid) {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['line_items', 'customer']]);
        if ($session->mode == 'subscription') {
            $this->save_subscription($session);
        } else {
            //If payment_intent doesn't exists in session ie. when using 100% coupon there is nothing to save.
            if ($session->payment_intent != null) {
                $this->save_payment_intent($session);
            }
        }
    }

    /**
     * Deliver course
     *
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $userid
     * @return void
     */
    public function deliver_course(string $component, string $paymentarea, int $itemid, int $userid) {
        $payable = helper::get_payable($component, $paymentarea, $itemid);
        $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), helper::get_gateway_surcharge('stripe'));
        $paymentid = helper::save_payment($payable->get_account_id(), $component, $paymentarea,
                $itemid, $userid, $cost, $payable->get_currency(), 'stripe');
        helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid);
    }

    /**
     * Find and return webhook endpoint if it exists.
     * Retrieve secret from Moodle database and add to webhook object.
     *
     * @param int $paymentaccountid
     * @return WebhookEndpoint|null
     * @throws ApiErrorException|\dml_exception
     */
    public function get_webhook(int $paymentaccountid): ?WebhookEndpoint {
        global $DB;

        if (!($record = $DB->get_record('paygw_stripe_webhooks', ['paymentaccountid' => $paymentaccountid]))) {
            return null;
        }

        if ($webhook = $this->stripe->webhookEndpoints->retrieve($record->webhookid)) {
            // Webhook still exists, lets set the secret and return.
            $webhook->secret = $record->secret;
            return $webhook;
        }

        return null;
    }

    /**
     * Create webhook for given account id if none already exists.
     *
     * @param int $paymentaccountid
     * @return bool True if webhook was created
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_webhook(int $paymentaccountid): bool {
        global $CFG, $DB;

        if ($this->get_webhook($paymentaccountid) != null) {
            return false;
        }

        $webhook = $this->stripe->webhookEndpoints->create([
                'url' => $CFG->wwwroot . '/payment/gateway/stripe/webhook.php',
                'enabled_events' => [
                        'checkout.session.completed',
                        'checkout.session.async_payment_succeeded',
                        'checkout.session.async_payment_failed',
                        'customer.subscription.deleted',
                        'customer.subscription.updated',
                ],
                'api_version' => self::$apiversion,
        ]);

        $datum = new \stdClass();
        $datum->paymentaccountid = $paymentaccountid;
        $datum->webhookid = $webhook->id;
        $datum->secret = $webhook->secret;
        $DB->insert_record('paygw_stripe_webhooks', $datum);

        return true;
    }

    /**
     * Process stripe payment events
     *
     * @param Event $event
     * @param array $metadata Array containing component, paymentarea, and itemid values set.
     * @return bool True if stripe data was valid, false otherwise.
     * @throws ApiErrorException|\dml_exception
     */
    public function process_stripe_event(Event $event, array $metadata): bool {
        global $DB;

        if (!isset($event->data->object)) {
            return false;
        }

        switch ($event->type) {
            // Process an async payment event.
            // Deliver the course if payment was successful or notify the user the payment failed.
            case 'checkout.session.async_payment_succeeded':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id, ['expand' => ['payment_intent']]);
                if (!($intentrecord = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent->id]))) {
                    return false;
                }
                $this->save_payment_status($session->id); // Update saved intent status.

                // Deliver course.
                $this->deliver_course($metadata['component'], $metadata['paymentarea'], $metadata['itemid'], $intentrecord->userid);

                // Notify user payment was successful.
                $url = helper::get_success_url($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $this->notify_user($intentrecord->userid, 'successful', ['url' => $url->out()]);
                break;
            case 'checkout.session.async_payment_failed':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id, ['expand' => ['payment_intent']]);
                if (!($intentrecord = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent->id]))) {
                    return false;
                }
                $this->save_payment_status($session->id); // Update saved intent status.
                // Notify user payment failed.
                $this->notify_user($intentrecord->userid, 'failed');
                break;
            // Handle customer subscriptions being deleted.
            case 'customer.subscription.deleted':
                if (!($moodlesub = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $event->data->object->id]))) {
                    return false;
                }
                $this->cancel_subscription($moodlesub, false);
                break;
            case 'customer.subscription.updated':
                if (!($moodlesub = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $event->data->object->id]))) {
                    return false;
                }
                $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
                $moodlesub->status = $subscription->status;
                $DB->update_record('paygw_stripe_subscriptions', $moodlesub);
                break;
            default:
                return false;
        }
        return true;
    }

    /**
     * Send message to user regarding payment status.
     *
     * @param int $userto User ID to send notification to
     * @param string $status Payment status
     * @param array $data Data passed to get_string
     * @return void
     * @throws \coding_exception
     */
    private function notify_user(int $userto, string $status, array $data = []) {
        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'paygw_stripe';
        $eventdata->name = 'payment_' . $status;
        $eventdata->notification = 1;
        $eventdata->userfrom = core_user::get_noreply_user();
        $eventdata->userto = $userto;
        $eventdata->subject = get_string('payment:' . $status . ':subject', 'paygw_stripe', $data);
        $eventdata->fullmessage = get_string('payment:' . $status . ':message', 'paygw_stripe', $data);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = '';
        if (isset($data['url'])) {
            $eventdata->contexturl = $data['url'];
        }
        message_send($eventdata);
    }

    /**
     * Get data table data for a specific subscription.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @return array Table data
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function get_subscription_table_data(\stdClass $moodlesub): ?array {
        $product = $this->stripe->products->retrieve($moodlesub->productid);
        $price = $this->stripe->prices->retrieve($moodlesub->priceid);
        try {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid, ['expand' => ['schedule']]);

            $cancellink =
                    new moodle_url('/payment/gateway/stripe/cancel.php', ['subscriptionid' => $moodlesub->id]);
            $portallink =
                    new moodle_url('/payment/gateway/stripe/subscriptions.php',
                            ['action' => 'portal', 'subscriptionid' => $moodlesub->id]);

            return [
                    $product->name,
                    $this->get_localised_cost($price->unit_amount, $price->currency) . ' / ' .
                    get_string('customsubscriptioninterval:' . $price->recurring->interval, 'paygw_stripe'),
                    userdate($subscription->current_period_end),
                    get_string('subscriptionstatus:' . $moodlesub->status, 'paygw_stripe'),
                    $moodlesub->status != 'canceled' ?
                            \html_writer::link($portallink, get_string('updatepaymentmethod', 'paygw_stripe')) : '',
                    $moodlesub->status != 'canceled' ? \html_writer::link($cancellink, get_string('cancel', 'paygw_stripe')) : '',
            ];
        } catch (ApiErrorException $err) {
            return null;
        }
    }

    /**
     * Cancel a given subscription.
     * Unenrol the user from a course if that was the product chosen.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @param bool $cancelstripe Attempt to cancel subscription within Stripe
     * @return void
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function cancel_subscription(\stdClass $moodlesub, bool $cancelstripe = true) {
        global $DB;

        if ($cancelstripe) {
            $subscription = $this->stripe->subscriptions->cancel($moodlesub->subscriptionid);
        } else {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        }
        $datum = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $moodlesub->subscriptionid]);
        $datum->status = $subscription->status;
        $DB->update_record('paygw_stripe_subscriptions', $datum);

        $product = $DB->get_record('paygw_stripe_products', ['productid' => $moodlesub->productid]);
        if (!$product) {
            return;
        }
        // A course was the product (any enrolment plugin, e.g. enrol_fee or enrol_feestripe).
        // Unenrol through the instance's own plugin: enrol_plugin::unenrol_user() refuses an
        // instance that belongs to a different plugin.
        if ($instance = self::resolve_enrol_instance($product->component, $product->itemid)) {
            if ($plugin = enrol_get_plugin($instance->enrol)) {
                $plugin->unenrol_user($instance, $moodlesub->userid);
            }
        } else if (strpos($product->component, 'enrol_') === 0) {
            debugging('paygw_stripe: enrol instance ' . $product->itemid . ' (' . $product->component .
                    ') not found, user ' . $moodlesub->userid . ' was not unenrolled.', DEBUG_DEVELOPER);
        }
    }

    /**
     * Redirects user to the Stripe subscription management portal.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @return void
     * @throws ApiErrorException
     */
    public function load_portal(\stdClass $moodlesub) {
        $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        $customer = $this->stripe->customers->retrieve($subscription->customer);

        $returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
        $session = $this->stripe->billingPortal->sessions->create([
                'customer' => $customer,
                'flow_data' => [
                        'type' => 'payment_method_update',
                        'after_completion' => [
                                'type' => 'redirect',
                                'redirect' => [
                                        'return_url' => $returnurl->out()
                                ]
                        ],
                ],
                'return_url' => $returnurl->out(),
        ]);

        header("HTTP/1.1 303 See Other");
        header("Location: " . $session->url);
    }

    /**
     * Turns the subscriptioninterval config setting into the data required for
     * creating a price.
     *
     * @param \stdClass $config
     * @return array
     */
    private function get_subscription_config_price_details($config): array {
        switch ($config->subscriptioninterval) {
            case 'daily':
                return [
                        'interval' => 'day',
                        'interval_count' => 1,
                ];
            case 'weekly':
                return [
                        'interval' => 'week',
                        'interval_count' => 1,
                ];
            case 'monthly':
                return [
                        'interval' => 'month',
                        'interval_count' => 1,
                ];
            case 'every3months':
                return [
                        'interval' => 'month',
                        'interval_count' => 3,
                ];
            case 'every6months':
                return [
                        'interval' => 'month',
                        'interval_count' => 6,
                ];
            case 'yearly':
                return [
                        'interval' => 'year',
                        'interval_count' => 1,
                ];
            case 'custom':
                return [
                        'interval' => $config->customsubscriptioninterval,
                        'interval_count' => $config->customsubscriptionintervalcount,
                ];
            default:
                return [
                        'interval' => 'month',
                        'interval_count' => 1
                ];
        }
    }

    /**
     * Retrieve start and end dates for anchored billing, based on config subscription settings.
     *
     * @param \stdClass $config
     * @return DateTime
     * @throws \Exception
     */
    private function get_anchor_billing_dates($config): DateTime {
        // Identify first day of the week for weekly intervals.
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $calendar = \core_calendar\type_factory::get_calendar_instance();
        $firstdayofweek = $days[$calendar->get_starting_weekday()];

        $dates = [
                'daily' => new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
                'weekly' => new DateTime('this ' . $firstdayofweek . ' 00:00:00', new DateTimeZone('UTC')),
                'monthly' => new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')),
                'every3months' => (new DateTime('first day of this month 00:00:00',
                        new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('3 months')),
                'every6months' => (new DateTime('first day of this month 00:00:00',
                        new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('6 months')),
                'yearly' => new DateTime('first day of this year 00:00:00', new DateTimeZone('UTC')),
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        if ($config->customsubscriptioninterval === 'day') {
            return (new DateTime('this day 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                    ' days'));
        } else {
            return (new DateTime('first day of this ' . $config->customsubscriptioninterval . ' 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                    ' ' . $config->customsubscriptioninterval . 's'));
        }
    }

    /**
     * Retrieve the end date of a trial period.
     *
     * @param \stdClass $config
     * @return DateTime
     * @throws \Exception
     */
    private function get_trial_end_date($config): DateTime {
        $dates = [
                'daily' => new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
                'weekly' => new DateTime('this day next week 00:00:00', new DateTimeZone('UTC')),
                'monthly' => new DateTime('this day next month 00:00:00', new DateTimeZone('UTC')),
                'every3months' => (new DateTime('this day next month 00:00:00',
                        new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('3 months')),
                'every6months' => (new DateTime('this day next month 00:00:00',
                        new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('6 months')),
                'yearly' => new DateTime('this day next year 00:00:00', new DateTimeZone('UTC'))
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        return (new DateTime('today 00:00:00',
                new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                ' ' . $config->customsubscriptioninterval . 's'));
    }

    /**
     * Generate a Stripe customer portal session URL.
     *
     * @param int $userid
     * @return string|null
     * @throws \dml_exception
     */
    public function get_customer_portal_url(int $userid): ?string {
        global $CFG, $DB;

        if (!$record = $DB->get_record('paygw_stripe_customers', ['userid' => $userid])) {
            return null;
        }

        try {
            $session = $this->stripe->billingPortal->sessions->create([
                    'customer' => $record->customerid,
                    'return_url' => (new \moodle_url('/user/profile.php'))->out(false),
            ]);
            return $session->url;
        } catch (\Exception $e) {
            debugging('Stripe customer portal error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}