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

namespace paygw_stripe;

use core_payment\form\account_gateway;
use Exception;
use paygw_stripe\local\service\invoice_service;
use paygw_stripe\local\service\stripe_service_factory;

/**
 * The gateway class for Stripe payment gateway.
 *
 * @package    paygw_stripe
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_payment\gateway {
    /**
     * The full list of currencies supported by Stripe regardless of account origin country.
     * Only certain currencies are supported based on the users account, the plugin does not account for that
     * when giving the list of supported currencies.
     *
     * {@link https://stripe.com/docs/currencies}
     *
     * @return string[]
     */
    public static function get_supported_currencies(): array {
        return [
            'USD', 'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD',
            'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK',
            'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG',
            'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR',
            'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN',
            'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR',
            'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
            'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
        ];
    }

    /**
     * The list of zero/non-decimal currencies in Stripe.
     *
     * {@link https://stripe.com/docs/currencies#zero-decimal}
     *
     * @return string[]
     */
    public static function get_zero_decimal_currencies(): array {
        return [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];
    }

    /**
     * Configuration form for the gateway instance
     *
     * Use $form->get_mform() to access the \MoodleQuickForm instance
     *
     * @param account_gateway $form
     */
    public static function add_configuration_to_gateway_form(account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('text', 'apikey', get_string('apikey', 'paygw_stripe'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_stripe');

        $mform->addElement('text', 'secretkey', get_string('secretkey', 'paygw_stripe'));
        $mform->setType('secretkey', PARAM_TEXT);
        $mform->addHelpButton('secretkey', 'secretkey', 'paygw_stripe');

        $existing = $form->get_gateway_persistent()->get_configuration();
        $options  = [];
        if (!empty($existing['apikey']) && !empty($existing['secretkey'])) {
            try {
                $factory = new stripe_service_factory($existing['apikey'], $existing['secretkey']);
                $configs = $factory->payment_method_config_service()->list_payment_method_configs();
                foreach ($configs as $config) {
                    $options[$config->id] = $config->name . ' (' . $config->id . ')';
                }
            } catch (Exception $e) {
                // Ignored, we don't want to break the form if we can't connect to Stripe.
                debugging('Could not fetch Stripe payment method configurations: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        if ($options) {
            $mform->addElement(
                'select',
                'paymentmethodconfiguration',
                get_string('paymentmethodconfiguration', 'paygw_stripe'),
                $options
            );
            $mform->addHelpButton('paymentmethodconfiguration', 'paymentmethodconfiguration', 'paygw_stripe');
            $mform->addElement(
                'static',
                'paymentmethodconfigurationlink',
                '',
                get_string('paymentmethodconfigurationlink', 'paygw_stripe')
            );
        } else {
            $mform->addElement(
                'static',
                'paymentmethodconfiguration_info',
                '',
                get_string('paymentmethodconfigurationsavekeysfirst', 'paygw_stripe')
            );
            $mform->addElement('hidden', 'paymentmethodconfiguration');
            $mform->setType('paymentmethodconfiguration', PARAM_ALPHANUMEXT);
        }

        // WSB Merito (upstream-sync, krok 10): pola naszej wersji zostają w formularzu, żeby zapis
        // formularza nie usuwał ich z konfiguracji konta. Zachowanie przenoszą grupy 11.4 i 11.5.
        // When enabled, the plugin omits the payment_method_types parameter when creating the
        // Stripe Checkout Session. Stripe then uses "dynamic payment methods", i.e. the set of
        // methods enabled and ordered in the Stripe Dashboard (Settings > Payment methods),
        // automatically filtered by currency, amount and customer location. No code change is
        // required to add or remove a method afterwards.
        $mform->addElement('advcheckbox', 'usedynamicpaymentmethods',
            get_string('usedynamicpaymentmethods', 'paygw_stripe'),
            get_string('usedynamicpaymentmethods_desc', 'paygw_stripe'));
        // WSB Merito: on by default – the upstream behaviour (payment method configuration) and production setting.
        $mform->setDefault('usedynamicpaymentmethods', true);
        $mform->addHelpButton('usedynamicpaymentmethods', 'usedynamicpaymentmethods', 'paygw_stripe');

        $paymentmethods = [
            'card' => get_string('paymentmethod:card', 'paygw_stripe'),
            'alipay' => get_string('paymentmethod:alipay', 'paygw_stripe'),
            'bancontact' => get_string('paymentmethod:bancontact', 'paygw_stripe'),
            'eps' => get_string('paymentmethod:eps', 'paygw_stripe'),
            'giropay' => get_string('paymentmethod:giropay', 'paygw_stripe'),
            'ideal' => get_string('paymentmethod:ideal', 'paygw_stripe'),
            'p24' => get_string('paymentmethod:p24', 'paygw_stripe'),
            'sepa_debit' => get_string('paymentmethod:sepa_debit', 'paygw_stripe'),
            'sofort' => get_string('paymentmethod:sofort', 'paygw_stripe'),
            'upi' => get_string('paymentmethod:upi', 'paygw_stripe'),
            'netbanking' => get_string('paymentmethod:netbanking', 'paygw_stripe'),
            'wechat_pay' => get_string('paymentmethod:wechat_pay', 'paygw_stripe'),
            'klarna' => get_string('paymentmethod:klarna', 'paygw_stripe')
        ];
        $method = $mform->addElement('select', 'paymentmethods', get_string('paymentmethods', 'paygw_stripe'), $paymentmethods);
        $mform->setType('paymentmethods', PARAM_TEXT);
        $mform->setDefault('paymentmethods', 'card');
        $method->setMultiple(true);
        // The manual list is irrelevant when dynamic payment methods are used, so hide it.
        $mform->hideIf('paymentmethods', 'usedynamicpaymentmethods', 'checked');
        // The payment method configuration applies only to dynamic payment methods.
        if ($mform->elementExists('paymentmethodconfiguration')) {
            $mform->hideIf('paymentmethodconfiguration', 'usedynamicpaymentmethods', 'notchecked');
        }

        // Tax handling mode. The three modes are mutually exclusive:
        //  - none:      the plugin adds no tax.
        //  - automatic: use Stripe Tax (automatic_tax). Requires Stripe Tax to be configured in the
        //               Dashboard (origin address, registrations, product tax codes).
        //  - manual:    apply a fixed Stripe Tax Rate (txr_...) to every line item. This is the free
        //               "Tax rates" feature and does not require Stripe Tax. Stripe forbids combining
        //               automatic_tax with manual tax_rates, hence a single selector rather than a
        //               separate checkbox.
        // Note: replaces the previous "enableautomatictax" checkbox; see stripe_helper for the
        // backward-compatibility fallback that still honours older saved configs.
        $mform->addElement('select', 'taxmode', get_string('taxmode', 'paygw_stripe'), [
            'none' => get_string('taxmode:none', 'paygw_stripe'),
            'automatic' => get_string('taxmode:automatic', 'paygw_stripe'),
            'manual' => get_string('taxmode:manual', 'paygw_stripe'),
        ]);
        $mform->setType('taxmode', PARAM_ALPHA);
        $mform->setDefault('taxmode', 'none');
        $mform->addHelpButton('taxmode', 'taxmode', 'paygw_stripe');

        // Manual Stripe Tax Rate ID (txr_...). Mode-specific in Stripe: a test-mode rate ID is not
        // valid in live mode, which is why this belongs in the per-account gateway config (each Moodle
        // payment account maps to a separate Stripe project/key) rather than a single global setting.
        $mform->addElement('text', 'manualtaxrate', get_string('manualtaxrate', 'paygw_stripe'));
        $mform->setType('manualtaxrate', PARAM_TEXT);
        $mform->addHelpButton('manualtaxrate', 'manualtaxrate', 'paygw_stripe');
        $mform->hideIf('manualtaxrate', 'taxmode', 'neq', 'manual');

        $mform->addElement('advcheckbox', 'allowpromotioncodes', get_string('allowpromotioncodes', 'paygw_stripe'));
        $mform->setDefault('allowpromotioncodes', true);

        // WSB Merito: the "enableautomatictax" checkbox is replaced by the tax mode above ("automatic"); older
        // configurations that only have the checkbox are still honoured (wsb_tax::is_automatic()).
        $mform->addElement('select', 'defaulttaxbehavior', get_string('defaulttaxbehavior', 'paygw_stripe'), [
            'exclusive' => get_string('taxbehavior:exclusive', 'paygw_stripe'),
            'inclusive' => get_string('taxbehavior:inclusive', 'paygw_stripe'),
        ]);
        $mform->addHelpButton('defaulttaxbehavior', 'defaulttaxbehavior', 'paygw_stripe');
        $mform->hideIf('defaulttaxbehavior', 'taxmode', 'neq', 'automatic');

        $mform->addElement(
            'advcheckbox',
            'collectbillingaddress',
            get_string('collectbillingaddress', 'paygw_stripe'),
            get_string('collectbillingaddress_desc', 'paygw_stripe')
        );
        // WSB Merito: invoices for buyers need the billing address – on by default (also for configurations saved before
        // the option existed, see checkout_service).
        $mform->setDefault('collectbillingaddress', true);

        $mform->addElement('select', 'type', get_string('paymenttype', 'paygw_stripe'), [
            'onetime' => get_string('paymenttype:onetime', 'paygw_stripe'),
            'subscription' => get_string('paymenttype:subscription', 'paygw_stripe'),
            'invoice' => get_string('paymenttype:invoice', 'paygw_stripe'),
        ]);
        $mform->setType('type', PARAM_TEXT);
        $mform->setDefault('type', 'onetime');
        $mform->addHelpButton('type', 'paymenttype', 'paygw_stripe');
        $mform->hideIf('allowpromotioncodes', 'type', 'eq', 'invoice');
        $mform->hideIf('collectbillingaddress', 'type', 'eq', 'invoice');
        $mform->hideIf('paymentmethodconfiguration', 'type', 'eq', 'invoice');

        $mform->addElement('advcheckbox', 'invoicepromotioncodes', get_string('invoicepromotioncodes', 'paygw_stripe'));
        $mform->setDefault('invoicepromotioncodes', false);
        $mform->addHelpButton('invoicepromotioncodes', 'invoicepromotioncodes', 'paygw_stripe');
        $mform->hideIf('invoicepromotioncodes', 'type', 'neq', 'invoice');

        $bankcountries = [];
        foreach (invoice_service::EU_BANK_TRANSFER_COUNTRIES as $country) {
            $bankcountries[$country] = get_string($country, 'countries');
        }
        $mform->addElement('select', 'invoicebankcountry', get_string('invoicebankcountry', 'paygw_stripe'), $bankcountries);
        $mform->setDefault('invoicebankcountry', invoice_service::DEFAULT_BANK_TRANSFER_COUNTRY);
        $mform->addHelpButton('invoicebankcountry', 'invoicebankcountry', 'paygw_stripe');
        $mform->hideIf('invoicebankcountry', 'type', 'neq', 'invoice');

        $mform->addElement(
            'advcheckbox',
            'invoicecreation',
            get_string('invoicecreation', 'paygw_stripe'),
            get_string('invoicecreation_desc', 'paygw_stripe')
        );
        // WSB Merito: every one-time payment gets an invoice (MER-39) – on by default.
        $mform->setDefault('invoicecreation', true);
        $mform->hideIf('invoicecreation', 'type', 'neq', 'onetime');

        $mform->addElement('select', 'subscriptioninterval', get_string('subscriptioninterval', 'paygw_stripe'), [
            'daily' => get_string('subscriptionperiod:daily', 'paygw_stripe'),
            'weekly' => get_string('subscriptionperiod:weekly', 'paygw_stripe'),
            'monthly' => get_string('subscriptionperiod:monthly', 'paygw_stripe'),
            'every3months' => get_string('subscriptionperiod:every3months', 'paygw_stripe'),
            'every6months' => get_string('subscriptionperiod:every6months', 'paygw_stripe'),
            'yearly' => get_string('subscriptionperiod:yearly', 'paygw_stripe'),
            'custom' => get_string('subscriptionperiod:custom', 'paygw_stripe'),
        ]);
        $mform->setType('subscriptioninterval', PARAM_TEXT);
        $mform->setDefault('subscriptioninterval', 'monthly');
        $mform->hideIf('subscriptioninterval', 'type', 'neq', 'subscription');

        $mform->addElement('select', 'customsubscriptioninterval', get_string('customsubscriptioninterval', 'paygw_stripe'), [
            'day' => get_string('customsubscriptioninterval:day', 'paygw_stripe'),
            'week' => get_string('customsubscriptioninterval:week', 'paygw_stripe'),
            'month' => get_string('customsubscriptioninterval:month', 'paygw_stripe'),
            'year' => get_string('customsubscriptioninterval:year', 'paygw_stripe'),
        ]);
        $mform->setType('customsubscriptioninterval', PARAM_TEXT);
        $mform->setDefault('customsubscriptioninterval', 'month');

        $mform->addElement(
            'text',
            'customsubscriptionintervalcount',
            get_string('customsubscriptionintervalcount', 'paygw_stripe')
        );
        $mform->setType('customsubscriptionintervalcount', PARAM_INT);
        $mform->setDefault('customsubscriptionintervalcount', 1);
        $mform->addHelpButton('customsubscriptionintervalcount', 'customsubscriptionintervalcount', 'paygw_stripe');
        $mform->addRule('customsubscriptionintervalcount', null, 'numeric', null, 'client');

        $mform->hideIf('customsubscriptioninterval', 'subscriptioninterval', 'neq', 'custom');
        $mform->hideIf('customsubscriptionintervalcount', 'subscriptioninterval', 'neq', 'custom');
        $mform->hideIf('customsubscriptioninterval', 'type', 'neq', 'subscription');
        $mform->hideIf('customsubscriptionintervalcount', 'type', 'neq', 'subscription');

        $mform->addElement(
            'advcheckbox',
            'anchorbilling',
            get_string('anchoredbilling', 'paygw_stripe'),
            get_string('anchoredbilling_help', 'paygw_stripe')
        );
        $mform->hideIf('anchorbilling', 'type', 'neq', 'subscription');

        $mform->addElement(
            'advcheckbox',
            'firstintervalfree',
            get_string('trialperiod', 'paygw_stripe'),
            get_string('trialperiod_help', 'paygw_stripe')
        );
        $mform->hideIf('firstintervalfree', 'type', 'neq', 'subscription');
    }

    /**
     * Validates the gateway configuration form.
     *
     * @param account_gateway $form
     * @param \stdClass $data
     * @param array $files
     * @param array $errors form errors (passed by reference)
     */
    public static function validate_gateway_form(
        account_gateway $form,
        \stdClass $data,
        array $files,
        array &$errors
    ): void {
        global $DB;
        // WSB Merito: without dynamic payment methods at least one method has to be selected.
        $usedynamic = !isset($data->usedynamicpaymentmethods) || !empty($data->usedynamicpaymentmethods);
        if ($data->enabled && (empty($data->apikey) || empty($data->secretkey) || (!$usedynamic && empty($data->paymentmethods)))) {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }

        // The manual tax mode is meaningless without a tax rate ID to apply.
        if ($data->enabled && ($data->taxmode ?? 'none') === 'manual'
                && trim($data->manualtaxrate ?? '') === '') {
            $errors['manualtaxrate'] = get_string('manualtaxrate_required', 'paygw_stripe');
        }

        if (
            ($data->type ?? '') === 'invoice' &&
                !in_array(
                    $data->invoicebankcountry ?? invoice_service::DEFAULT_BANK_TRANSFER_COUNTRY,
                    invoice_service::EU_BANK_TRANSFER_COUNTRIES,
                    true
                )
        ) {
            $errors['invoicebankcountry'] = get_string('invalidinvoicebankcountry', 'paygw_stripe');
        }

        // Very hacky as this shouldn't live in a validation function, but due to Moodle limitations it's placed here.
        // Check if API keys have changed, remove existing webhook and create new.
        $existingdata = $form->get_gateway_persistent()->get_configuration();

        $newapikey = $data->apikey ?? null;
        $newsecret = $data->secretkey ?? null;

        $oldapikey = $existingdata['apikey'] ?? null;
        $oldsecret = $existingdata['secretkey'] ?? null;

        $apikeychanged = ($newapikey !== null) && ($newapikey !== $oldapikey);
        $secretchanged = ($newsecret !== null) && ($newsecret !== $oldsecret);

        if ($apikeychanged || $secretchanged) {
            $paymentaccountid = $form->get_gateway_persistent()->get_account()->get('id');

            $DB->delete_records('paygw_stripe_webhooks', ['paymentaccountid' => $paymentaccountid]);

            try {
                if (is_string($oldapikey) && $oldapikey !== '' && is_string($oldsecret) && $oldsecret !== '') {
                    $factory = new stripe_service_factory($oldapikey, $oldsecret);
                    $webhookservice = $factory->webhook_service();
                    $webhookservice->delete_webhook($paymentaccountid);
                }

                $factory = new stripe_service_factory($newapikey, $newsecret);
                $webhookservice = $factory->webhook_service();
                $webhookservice->create_webhook($paymentaccountid);
            } catch (Exception $ignored) {
                $errors['apikey'] = get_string('apiwebhookerror', 'paygw_stripe');
            }
        }
    }
}
