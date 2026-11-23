<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace paygw_stripe\local\service;

use core_payment\local\entities\payable;
use core_payment\helper;
use DateInterval;
use DateTime;
use DateTimeZone;
use moodle_url;
use paygw_stripe\gateway;
use paygw_stripe\local\model\subscription;
use paygw_stripe\local\repository\product_repository;
use paygw_stripe\local\repository\subscription_repository;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Subscription service.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscription_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * @var subscription_repository The subscription repository.
     */
    private subscription_repository $subscriptionrepository;
    /**
     * @var product_repository The product repository.
     */
    private product_repository $productrepository;
    /**
     * @var product_pricing_service The product pricing service.
     */
    private product_pricing_service $productpricingservice;
    /**
     * @var customer_service The customer service.
     */
    private customer_service $customerservice;
    /**
     * @var locale_service The locale service.
     */
    private locale_service $localeservice;
    /**
     * @var webhook_service The webhook service.
     */
    private webhook_service $webhookservice;
    /**
     * @var payment_method_config_service The payment method config service.
     */
    private payment_method_config_service $paymentmethodconfigservice;

    /**
     * Subscription service constructor.
     *
     * @param StripeClient $stripe The Stripe API client.
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->subscriptionrepository = new subscription_repository();
        $this->productrepository = new product_repository();

        $this->productpricingservice = new product_pricing_service($stripe);
        $this->customerservice = new customer_service($stripe);
        $this->localeservice = new locale_service();
        $this->webhookservice = new webhook_service($stripe);
        $this->paymentmethodconfigservice = new payment_method_config_service($stripe);
    }

    /**
     * Retrieve Stipe subscription details and save in Moodle based on Stripe checkout session.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    public function save_subscription(Session $session) {
        global $USER;

        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);

        $msub = $this->subscriptionrepository->find_by_subscriptionid($session->subscription);
        if ($msub != null) {
            $this->subscriptionrepository->update_status($msub->id, $subscription->status);
            return;
        }

        $record = new subscription(
            null,
            (int)$USER->id,
            $session->subscription,
            $session->customer->id,
            $subscription->status,
            $session->line_items->first()->price->product,
            $session->line_items->first()->price->id
        );
        $this->subscriptionrepository->save($record);
    }

    /**
     * Deliver initial subscription access once, shared by browser returns and webhooks.
     *
     * @param string $sessionid Checkout session ID
     * @param int $userid Purchasing user ID
     * @param string $component Payment component
     * @param string $paymentarea Payment area
     * @param int $itemid Purchased item ID
     * @return bool False when this subscription was already delivered
     */
    public function fulfil_subscription(
        string $sessionid,
        int $userid,
        string $component,
        string $paymentarea,
        int $itemid
    ): bool {
        global $DB;

        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['line_items', 'customer']]);
        if (!$this->session_matches_request($session, $userid, $component, $paymentarea, $itemid)) {
            throw new \moodle_exception('invalidsessionbinding', 'paygw_stripe');
        }
        if (!$session->subscription || $session->status !== 'complete') {
            throw new \moodle_exception('subscriptionerror', 'paygw_stripe');
        }

        $factory = \core\lock\lock_config::get_lock_factory('paygw_stripe');
        $lock = $factory->get_lock('subscription_' . hash('sha256', $session->subscription), 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $record = $this->subscriptionrepository->find_by_subscriptionid($session->subscription);
            if ($record && ($record->userid !== $userid || $record->productid !== $session->line_items->first()->price->product)) {
                throw new \moodle_exception('invalidsessionbinding', 'paygw_stripe');
            }
            if ($record && $record->delivered) {
                return false;
            }

            $subscription = $this->stripe->subscriptions->retrieve($session->subscription);
            if (
                !in_array($subscription->status, ['active', 'trialing'], true) ||
                !in_array($session->payment_status, ['paid', 'no_payment_required'], true)
            ) {
                throw new \moodle_exception('subscriptionerror', 'paygw_stripe');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $record = new subscription(
                    $record ? $record->id : null,
                    $userid,
                    $session->subscription,
                    $session->customer->id,
                    $subscription->status,
                    $session->line_items->first()->price->product,
                    $session->line_items->first()->price->id
                );
                $payable = helper::get_payable($component, $paymentarea, $itemid);
                $cost = helper::get_rounded_cost(
                    $payable->get_amount(),
                    $payable->get_currency(),
                    helper::get_gateway_surcharge('stripe')
                );
                $paymentid = helper::save_payment(
                    $payable->get_account_id(),
                    $component,
                    $paymentarea,
                    $itemid,
                    $userid,
                    $cost,
                    $payable->get_currency(),
                    'stripe'
                );
                if (!helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid)) {
                    throw new \moodle_exception('subscriptiondeliveryfailed', 'paygw_stripe');
                }
                $saved = $this->subscriptionrepository->save($record->with_delivered());
                // WSB Merito: store the payment item with the subscription – the product mapping is not unique
                // (one Stripe product can serve several enrolment instances) and can be removed later.
                $DB->update_record('paygw_stripe_subscriptions', (object) ['id' => $saved->id, 'component' => $component,
                    'paymentarea' => $paymentarea, 'itemid' => $itemid]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Find a stored subscription by Stripe subscription id.
     *
     * @param string $subscriptionid
     * @return subscription|null
     * @throws \dml_exception
     */
    public function find_subscription(string $subscriptionid): ?subscription {
        return $this->subscriptionrepository->find_by_subscriptionid($subscriptionid);
    }

    /**
     * Refresh the stored status for a subscription from Stripe.
     *
     * @param subscription $moodlesub
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function sync_status(subscription $moodlesub): void {
        $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        $this->subscriptionrepository->update_status($moodlesub->id, $subscription->status);
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
    public function generate_subscription(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid
    ): ?string {
        global $CFG, $USER;

        // Ensure webhook exists before we use it.
        $this->webhookservice->create_webhook($payable->get_account_id());

        $pricedetails = $this->get_subscription_config_price_details($config);

        [$product, $price] = $this->productpricingservice->create_product_and_price(
            $config,
            $payable,
            $description,
            $cost,
            $component,
            $paymentarea,
            $itemid,
            $pricedetails
        );

        // WSB Merito: profile data from the database, not the session copy in $USER – changes made while the
        // buyer is logged in reach Stripe right away.
        $buyer = \core_user::get_user($USER->id, '*', MUST_EXIST);
        if (!$customer = $this->customerservice->get_customer((int)$buyer->id)) {
            $customer = $this->customerservice->create_customer($buyer);
        } else {
            $customer = $this->customerservice->update_customer_details($customer, $buyer);
        }

        $stripelocale = $this->localeservice->get_stripe_locale_for_user($USER);

        // WSB Merito: Dashboard methods (payment method configuration) or the methods selected on the account.
        $paymentmethodparams = wsb_payment_methods::session_params($config, $this->paymentmethodconfigservice);

        $subscriptiondata = [
            'metadata' => wsb_metadata::payment($buyer, $component, $paymentarea, $itemid),
        ];
        // WSB Merito: in manual tax mode the fixed Stripe Tax Rate (e.g. "zw") applies to every invoice of the
        // subscription – subscription_data.default_tax_rates (line_items.tax_rates is for one-time payments).
        if ($taxrate = wsb_tax::manual_rate($config)) {
            $subscriptiondata['default_tax_rates'] = [$taxrate];
        }
        if ($config->anchorbilling) {
            $subscriptiondata['billing_cycle_anchor'] = $this->get_anchor_billing_dates($config)->getTimestamp();
            if ($config->firstintervalfree) {
                $subscriptiondata['proration_behavior'] = 'none';
            }
        }
        if ($config->firstintervalfree && !$config->anchorbilling) {
            $subscriptiondata['trial_end'] = $this->get_trial_end_date($config)->getTimestamp();
        }

        // Create checkout session to set up subscription for customer.
        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'locale' => $stripelocale,
            'managed_payments' => ['enabled' => false],
            ...$paymentmethodparams,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $price,
                'quantity' => 1,
            ]],
            'automatic_tax' => [
                'enabled' => wsb_tax::is_automatic($config),
            ],
            // WSB Merito: absent setting = on (see checkout_service); tax ID for company invoices.
            'billing_address_collection' => ($config->collectbillingaddress ?? 1) == 1 ? 'required' : 'auto',
            'tax_id_collection' => ['enabled' => true],
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            'subscription_data' => $subscriptiondata,
            'customer' => $customer->id,
            'metadata' => wsb_metadata::payment($buyer, $component, $paymentarea, $itemid),
            'customer_update' => [
                'name' => 'auto',
                'address' => 'auto',
            ],
            'expires_at' => time() + min($CFG->sessiontimeout, 24 * 60 * 60),
            'expand' => ['customer', 'line_items'],
        ]);

        return $session->id;
    }

    /**
     * Redirects user to the Stripe subscription management portal.
     *
     * @param subscription $moodlesub Moodle subscription record
     * @return void
     * @throws ApiErrorException
     */
    public function load_portal(subscription $moodlesub) {
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
                        'return_url' => $returnurl->out(),
                    ],
                ],
            ],
            'return_url' => $returnurl->out(),
        ]);

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return;
        }
        header("HTTP/1.1 303 See Other");
        header("Location: " . $session->url);
    }

    /**
     * Cancel a given subscription.
     * Unenrol the user from a course if that was the product chosen.
     *
     * WSB Merito: the current state is read from Stripe first. A subscription that is already cancelled in
     * Stripe (Dashboard, customer portal, failed renewals with a lost webhook) or no longer exists there is not
     * cancelled again – that call threw ("No such subscription") and left Moodle out of sync. In both cases the
     * record gets the Stripe status ("canceled" for a missing one) and the user is unenrolled through the
     * instance's own enrolment plugin (any enrol_*, e.g. enrol_feestripe), so access matches what is paid for.
     *
     * @param subscription $moodlesub Moodle subscription record
     * @param bool $cancelstripe Attempt to cancel subscription within Stripe
     * @return bool True if this call cancelled the subscription in Stripe.
     * @throws ApiErrorException Errors other than a missing subscription
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function cancel_subscription(subscription $moodlesub, bool $cancelstripe = true): bool {
        global $DB;

        $subscription = $this->retrieve_subscription_or_null($moodlesub->subscriptionid);
        $cancelled = false;
        if ($cancelstripe && $subscription && $subscription->status !== 'canceled') {
            $subscription = $this->stripe->subscriptions->cancel($moodlesub->subscriptionid);
            $cancelled = true;
        }
        $status = $subscription ? $subscription->status : 'canceled';

        $msub = $this->subscriptionrepository->find_by_subscriptionid($moodlesub->subscriptionid);
        $this->subscriptionrepository->update_status($msub->id, $status);
        if ($status !== 'canceled') {
            return $cancelled;
        }

        $record = $DB->get_record('paygw_stripe_subscriptions', ['id' => $msub->id]);
        $item = wsb_purchase_guard::get_subscription_item($record);
        if (!$item) {
            debugging('paygw_stripe: no payment item for subscription ' . $moodlesub->subscriptionid . ', user ' .
                $moodlesub->userid . ' was not unenrolled.', DEBUG_DEVELOPER);
            return $cancelled;
        }
        // Unenrol through the instance's own plugin: enrol_plugin::unenrol_user() refuses an instance of another plugin.
        if ($instance = wsb_metadata::resolve_enrol_instance($item->component, $item->itemid)) {
            if ($plugin = enrol_get_plugin($instance->enrol)) {
                $plugin->unenrol_user($instance, $moodlesub->userid);
            }
        } else if (strpos($item->component, 'enrol_') === 0) {
            debugging('paygw_stripe: enrol instance ' . $item->itemid . ' (' . $item->component . ') not found, user ' .
                $moodlesub->userid . ' was not unenrolled.', DEBUG_DEVELOPER);
        }
        return $cancelled;
    }

    /**
     * Compare one stored subscription with Stripe and catch up on a change that Moodle missed (WSB Merito).
     *
     * Used by the daily reconcile_subscriptions task as a safety net for lost webhooks. Ended in Stripe: the same
     * handling as the webhook (status and unenrolment). Another status: only stored (access never depends on it).
     * Missing in Stripe: nothing changes – a real subscription is never deleted, so this means wrong API keys or a
     * broken record, and unenrolling would be a guess. Never grants access.
     *
     * @param subscription $moodlesub
     * @return array{0: string, 1: string|null} Outcome ('ended', 'updated', 'unchanged' or 'missing') and the Stripe status.
     * @throws ApiErrorException Errors other than a missing subscription
     */
    public function reconcile_subscription(subscription $moodlesub): array {
        $subscription = $this->retrieve_subscription_or_null($moodlesub->subscriptionid);
        if (!$subscription) {
            return ['missing', null];
        }
        if ($subscription->status === 'canceled') {
            $this->cancel_subscription($moodlesub, false);
            return ['ended', 'canceled'];
        }
        if ($subscription->status !== $moodlesub->status) {
            $this->subscriptionrepository->update_status($moodlesub->id, $subscription->status);
            return ['updated', $subscription->status];
        }
        return ['unchanged', $subscription->status];
    }

    /**
     * Cancel at the end of the paid billing period (WSB Merito; what the user does on "My subscriptions").
     *
     * Stripe keeps the subscription active until the period ends (cancel_at_period_end) and does not charge
     * again; then it sends customer.subscription.deleted and the webhook unenrols the user. Until then the user
     * keeps access and can resume. A subscription that is not being paid for (incomplete, unpaid) is cancelled
     * immediately; one already ended or missing in Stripe is only synced.
     *
     * @param subscription $moodlesub
     * @return array{0: string, 1: int|null} Outcome ('scheduled', 'cancelled' or 'alreadyended') and, for
     *     'scheduled', when access ends.
     * @throws ApiErrorException
     */
    public function schedule_cancellation(subscription $moodlesub): array {
        $subscription = $this->retrieve_subscription_or_null($moodlesub->subscriptionid);
        if (!$subscription || in_array($subscription->status, ['canceled', 'incomplete_expired'])) {
            $this->cancel_subscription($moodlesub, false);
            return ['alreadyended', null];
        }
        if (in_array($subscription->status, ['incomplete', 'unpaid'])) {
            $this->cancel_subscription($moodlesub);
            return ['cancelled', null];
        }
        if (empty($subscription->cancel_at_period_end)) {
            $subscription = $this->stripe->subscriptions->update($moodlesub->subscriptionid, ['cancel_at_period_end' => true]);
        }
        $this->subscriptionrepository->update_status($moodlesub->id, $subscription->status);
        return ['scheduled', self::period_end($subscription)];
    }

    /**
     * Undo a cancellation scheduled for the end of the period (WSB Merito). An ended subscription cannot be resumed.
     *
     * @param subscription $moodlesub
     * @return int|null Next renewal, or null when the subscription cannot be resumed.
     * @throws ApiErrorException
     */
    public function resume_subscription(subscription $moodlesub): ?int {
        $subscription = $this->retrieve_subscription_or_null($moodlesub->subscriptionid);
        if (!$subscription || in_array($subscription->status, ['canceled', 'incomplete_expired', 'incomplete', 'unpaid'])) {
            return null;
        }
        if (!empty($subscription->cancel_at_period_end)) {
            $subscription = $this->stripe->subscriptions->update($moodlesub->subscriptionid, ['cancel_at_period_end' => false]);
        }
        $this->subscriptionrepository->update_status($moodlesub->id, $subscription->status);
        return self::period_end($subscription);
    }

    /**
     * End of the current paid period, i.e. until when a cancelled subscription keeps access (WSB Merito).
     *
     * @param subscription $moodlesub
     * @return int|null Null when there is no paid period to keep (ended, missing, incomplete or unpaid).
     * @throws ApiErrorException
     */
    public function get_current_period_end(subscription $moodlesub): ?int {
        $subscription = $this->retrieve_subscription_or_null($moodlesub->subscriptionid);
        if (!$subscription || in_array($subscription->status, ['canceled', 'incomplete_expired', 'incomplete', 'unpaid'])) {
            return null;
        }
        return self::period_end($subscription);
    }

    /**
     * End of the current billing period. Since API 2025-03-31 it is on the subscription items; older versions had
     * it on the subscription.
     *
     * @param \Stripe\Subscription $subscription
     * @return int|null
     */
    public static function period_end(\Stripe\Subscription $subscription): ?int {
        $item = $subscription->items->data[0] ?? null;
        $end = $item->current_period_end ?? ($subscription->current_period_end ?? null);
        return $end ? (int) $end : null;
    }

    /**
     * Format a date for subscription messages (day, month and year in the user's language).
     *
     * @param int $timestamp
     * @return string
     */
    public static function format_subscription_date(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedate', 'langconfig'));
    }

    /**
     * Retrieve a subscription from Stripe, or null when it no longer exists there.
     *
     * @param string $subscriptionid
     * @return \Stripe\Subscription|null
     * @throws ApiErrorException Errors other than a missing subscription
     */
    private function retrieve_subscription_or_null(string $subscriptionid): ?\Stripe\Subscription {
        try {
            return $this->stripe->subscriptions->retrieve($subscriptionid);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }
            debugging('paygw_stripe: subscription ' . $subscriptionid . ' not found in Stripe.', DEBUG_DEVELOPER);
            return null;
        }
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
     * Get data table data for a specific subscription.
     *
     * @param subscription $moodlesub Moodle subscription record
     * @return array Table data
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function get_subscription_table_data(subscription $moodlesub): ?array {
        $product = $this->stripe->products->retrieve($moodlesub->productid);
        $price = $this->stripe->prices->retrieve($moodlesub->priceid);
        try {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid, ['expand' => ['schedule']]);

            $cancellink =
                new moodle_url('/payment/gateway/stripe/cancel.php', ['subscriptionid' => $moodlesub->id]);
            $portallink =
                new moodle_url(
                    '/payment/gateway/stripe/subscriptions.php',
                    ['action' => 'portal', 'subscriptionid' => $moodlesub->id]
                );

            $fee = $this->get_localised_cost($price->unit_amount, $price->currency) . ' / ' .
                get_string('customsubscriptioninterval:' . $price->recurring->interval, 'paygw_stripe');
            // WSB Merito: cancelled at the end of the paid period – still active but will not renew. Show when access
            // ends and offer to resume instead of cancelling again.
            if (($subscription->status ?? null) !== 'canceled' && !empty($subscription->cancel_at_period_end)) {
                $resumelink = new moodle_url(
                    '/payment/gateway/stripe/subscriptions.php',
                    ['action' => 'resume', 'subscriptionid' => $moodlesub->id, 'sesskey' => sesskey()]
                );
                return [
                    s($product->name),
                    $fee,
                    '–',
                    get_string(
                        'subscriptionendson',
                        'paygw_stripe',
                        self::format_subscription_date(self::period_end($subscription))
                    ),
                    '',
                    \html_writer::link($resumelink, get_string('resumesubscription', 'paygw_stripe')),
                ];
            }

            return [
                s($product->name),
                $fee,
                ($subscription->status ?? null) !== 'canceled' ? userdate(self::period_end($subscription)) : '–',
                get_string('subscriptionstatus:' . $moodlesub->status, 'paygw_stripe'),
                $moodlesub->status != 'canceled' ?
                    \html_writer::link($portallink, get_string('updatepaymentmethod', 'paygw_stripe')) : '',
                $moodlesub->status != 'canceled' ? \html_writer::link($cancellink, get_string('cancel', 'paygw_stripe')) : '',
            ];
        } catch (ApiErrorException $err) {
            // WSB Merito: skip the row instead of breaking the page, but leave a trace for the administrator.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- must reach the server log, also with debugging off.
            error_log('paygw_stripe: cannot load subscription ' . $moodlesub->subscriptionid . ' for the list: ' .
                $err->getMessage());
            return null;
        }
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
                    'interval_count' => 1,
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
            'every3months' => (new DateTime(
                'first day of this month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('3 months')),
            'every6months' => (new DateTime(
                'first day of this month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('6 months')),
            'yearly' => new DateTime('first day of this year 00:00:00', new DateTimeZone('UTC')),
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        if ($config->customsubscriptioninterval === 'day') {
            return (new DateTime(
                'this day 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                ' days'));
        } else {
            return (new DateTime(
                'first day of this ' . $config->customsubscriptioninterval . ' 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
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
            'every3months' => (new DateTime(
                'this day next month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('3 months')),
            'every6months' => (new DateTime(
                'this day next month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('6 months')),
            'yearly' => new DateTime('this day next year 00:00:00', new DateTimeZone('UTC')),
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        return (new DateTime(
            'today 00:00:00',
            new DateTimeZone('UTC')
        ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
            ' ' . $config->customsubscriptioninterval . 's'));
    }

    /**
     * Check if the given session is bound to the given request.
     *
     * @param string $sessionid
     * @param int $userid
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @return bool
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function is_session_bound_to_request(
        string $sessionid,
        int $userid,
        string $component,
        string $paymentarea,
        int $itemid
    ): bool {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, [
            'expand' => ['line_items', 'subscription'],
        ]);

        return $this->session_matches_request($session, $userid, $component, $paymentarea, $itemid);
    }

    /**
     * Validate the purchaser and product of a retrieved subscription Checkout session.
     *
     * @param Session $session Stripe Checkout session
     * @param int $userid Purchasing user ID
     * @param string $component Payment component
     * @param string $paymentarea Payment area
     * @param int $itemid Purchased item ID
     * @return bool
     */
    private function session_matches_request(
        Session $session,
        int $userid,
        string $component,
        string $paymentarea,
        int $itemid
    ): bool {
        if ($session->mode !== 'subscription') {
            return false;
        }

        // Prefer metadata user binding.
        if ($userid <= 0 || (int)($session->metadata->userid ?? 0) !== $userid) {
            return false;
        }

        // Bind requested item to the paid product.
        $productid = $session->line_items->first()->price->product ?? null;
        if (!$productid) {
            return false;
        }

        $product = $this->productrepository->find_by_productid($productid);
        if (!$product) {
            return false;
        }

        return $product->component === $component
            && $product->paymentarea === $paymentarea
            && $product->itemid === $itemid;
    }
}
