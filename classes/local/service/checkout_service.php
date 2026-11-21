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
use paygw_stripe\local\model\checkout_session;
use paygw_stripe\local\repository\checkout_session_repository;
use paygw_stripe\local\repository\product_repository;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Checkout service.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkout_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;
    /**
     * @var checkout_session_repository
     */
    private checkout_session_repository $checkoutrepository;
    /**
     * @var product_repository
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
     * @var subscription_service The subscription service.
     */
    private subscription_service $subscriptionservice;
    /**
     * @var payment_method_config_service The payment method configuration service.
     */
    private payment_method_config_service $paymentmethodconfigservice;

    /**
     * Checkout service constructor.
     *
     * @param StripeClient $stripe
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->checkoutrepository = new checkout_session_repository();
        $this->productrepository = new product_repository();
        $this->productpricingservice = new product_pricing_service($stripe);
        $this->customerservice = new customer_service($stripe);
        $this->localeservice = new locale_service();
        $this->webhookservice = new webhook_service($stripe);
        $this->subscriptionservice = new subscription_service($stripe);
        $this->paymentmethodconfigservice = new payment_method_config_service($stripe);
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
    public function generate_payment(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid
    ): string {
        global $CFG, $USER;

        // Ensure webhook exists before we potentially use it.
        $this->webhookservice->create_webhook($payable->get_account_id());

        [$product, $price] = $this->productpricingservice->create_product_and_price(
            $config,
            $payable,
            $description,
            $cost,
            $component,
            $paymentarea,
            $itemid
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

        $paymentmethodconfigurationid = $config->paymentmethodconfiguration;
        if ($paymentmethodconfigurationid == null) {
            $paymentmethodconfigurationid = $this->paymentmethodconfigservice->get_default_payment_method_config_id();
        }

        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'locale' => $stripelocale,
            'managed_payments' => ['enabled' => false],
            'payment_method_configuration' => $paymentmethodconfigurationid,
            'payment_method_options' => [
                'wechat_pay' => [
                    'client' => "web",
                ],
            ],
            'mode' => 'payment',
            // WSB Merito: in manual tax mode the fixed Stripe Tax Rate (e.g. "zw") goes on the line item.
            'line_items' => [array_filter([
                'price' => $price,
                'quantity' => 1,
                'tax_rates' => ($taxrate = wsb_tax::manual_rate($config)) ? [$taxrate] : null,
            ])],
            'automatic_tax' => [
                'enabled' => wsb_tax::is_automatic($config),
            ],
            // WSB Merito: an absent setting (configuration saved before the option existed) means on – invoices with
            // the billing address and tax ID for every purchase, as in the WSB version (MER-36, MER-39).
            'billing_address_collection' => ($config->collectbillingaddress ?? 1) == 1 ? 'required' : 'auto',
            'tax_id_collection' => ['enabled' => true],
            'invoice_creation' => [
                'enabled' => ($config->invoicecreation ?? 1) == 1,
                'invoice_data' => [
                    'issuer' => [
                        'type' => 'self',
                    ],
                    'description' => $description,
                    // An invoice inherits no metadata from the payment that created it.
                    'metadata' => wsb_metadata::payment($buyer, $component, $paymentarea, $itemid),
                ],
            ],
            'customer' => $customer->id,
            'metadata' => wsb_metadata::payment($buyer, $component, $paymentarea, $itemid),
            'payment_intent_data' => [
                'metadata' => wsb_metadata::payment($buyer, $component, $paymentarea, $itemid),
                // WSB Merito: fills the "Description" column of the Stripe payments list (the product name).
                'description' => $description,
            ],
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            // WSB Merito: the name entered in Checkout (e.g. a company name with its tax ID) is stored on the customer
            // and used on invoices; required by tax_id_collection for an existing customer.
            'customer_update' => [
                'name' => 'auto',
                'address' => 'auto',
            ],
            'expires_at' => time() + min($CFG->sessiontimeout, 24 * 60 * 60),
            'expand' => ['customer', 'line_items'],
        ]);

        $this->save_checkout_session($session);

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
     * Save checkout session status with customer and product details.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    public function save_checkout_session(Session $session) {
        global $USER;

        $storedsession = $this->checkoutrepository->find_by_sessionid($session->id);
        if ($storedsession != null) {
            // WSB Merito: also the amount actually charged (after promotion codes) and the PaymentIntent.
            $storedsession = $storedsession->with_status($session->status)->with_paymentstatus($session->payment_status)
                ->with_payment($session->payment_intent, (int) $session->amount_total);
            $this->checkoutrepository->save($storedsession);
            return;
        }

        $record = new checkout_session(
            null,
            (int)$USER->id,
            $session->id,
            $session->payment_intent,
            $session->customer->id,
            $session->amount_total,
            $session->payment_status,
            $session->status,
            $session->line_items->first()->price->product,
            false
        );

        $this->checkoutrepository->save($record);
    }

    /**
     * Check if a checkout session has been saved in the DB.
     *
     * @param string $sessionid
     * @return bool
     * @throws \dml_exception
     */
    public function is_checkout_session_saved(string $sessionid): bool {
        if ($this->checkoutrepository->find_by_sessionid($sessionid)) {
            return true;
        }
        return false;
    }

    /**
     * Check if a checkout session has been marked as delivered.
     *
     * @param string $sessionid
     * @return bool
     * @throws \dml_exception
     */
    public function is_delivered(string $sessionid): bool {
        $session = $this->checkoutrepository->find_by_sessionid($sessionid);
        if ($session) {
            return $session->delivered;
        }
        return false;
    }

    /**
     * Mark a checkout session as delivered.
     *
     * @param string $sessionid
     * @return void
     */
    public function mark_delivered(string $sessionid) {
        $session = $this->checkoutrepository->find_by_sessionid($sessionid);
        if ($session) {
            $session = $session->with_delivered(true);
            $this->checkoutrepository->save($session);
        }
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
            $this->subscriptionservice->save_subscription($session);
        } else {
            $this->save_checkout_session($session);
        }
    }

    /**
     * Find a stored checkout session by Stripe session id.
     *
     * @param string $sessionid
     * @return checkout_session|null
     * @throws \dml_exception
     */
    public function find_session(string $sessionid): ?checkout_session {
        return $this->checkoutrepository->find_by_sessionid($sessionid);
    }

    /**
     * Is the checkout session bound to the request (component, paymentarea, itemid, userid) ?
     *
     * @param string $sessionid
     * @param int $userid
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @return bool
     * @throws \dml_exception
     */
    public function is_session_bound_to_request(
        string $sessionid,
        int $userid,
        string $component,
        string $paymentarea,
        int $itemid
    ): bool {
        $session = $this->checkoutrepository->find_by_sessionid($sessionid);
        if (!$session || $session->userid !== $userid) {
            return false;
        }

        $product = $this->productrepository->find_by_productid($session->productid);
        if (!$product) {
            return false;
        }

        return $product->component === $component
            && $product->paymentarea === $paymentarea
            && $product->itemid === $itemid;
    }
}
