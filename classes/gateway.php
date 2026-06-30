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
 * Contains class for Stripe payment gateway.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

use core_payment\form\account_gateway;

/**
 * The gateway class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
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
            'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'
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

        // When enabled, the plugin omits the payment_method_types parameter when creating the
        // Stripe Checkout Session. Stripe then uses "dynamic payment methods", i.e. the set of
        // methods enabled and ordered in the Stripe Dashboard (Settings > Payment methods),
        // automatically filtered by currency, amount and customer location. No code change is
        // required to add or remove a method afterwards.
        $mform->addElement('advcheckbox', 'usedynamicpaymentmethods',
            get_string('usedynamicpaymentmethods', 'paygw_stripe'),
            get_string('usedynamicpaymentmethods_desc', 'paygw_stripe'));
        $mform->setDefault('usedynamicpaymentmethods', false);
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

        $mform->addElement('advcheckbox', 'allowpromotioncodes', get_string('allowpromotioncodes', 'paygw_stripe'));
        $mform->setDefault('allowpromotioncodes', true);

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

        // Default tax behavior only applies to Stripe Tax (automatic mode). For manual tax rates the
        // inclusive/exclusive setting is a property of the Tax Rate object itself, set in the Dashboard.
        $mform->addElement('select', 'defaulttaxbehavior', get_string('defaulttaxbehavior', 'paygw_stripe'), [
            'exclusive' => get_string('taxbehavior:exclusive', 'paygw_stripe'),
            'inclusive' => get_string('taxbehavior:inclusive', 'paygw_stripe'),
        ]);
        $mform->addHelpButton('defaulttaxbehavior', 'defaulttaxbehavior', 'paygw_stripe');
        $mform->hideIf('defaulttaxbehavior', 'taxmode', 'neq', 'automatic');

        // Manual Stripe Tax Rate ID (txr_...). Mode-specific in Stripe: a test-mode rate ID is not
        // valid in live mode, which is why this belongs in the per-account gateway config (each Moodle
        // payment account maps to a separate Stripe project/key) rather than a single global setting.
        $mform->addElement('text', 'manualtaxrate', get_string('manualtaxrate', 'paygw_stripe'));
        $mform->setType('manualtaxrate', PARAM_TEXT);
        $mform->addHelpButton('manualtaxrate', 'manualtaxrate', 'paygw_stripe');
        $mform->hideIf('manualtaxrate', 'taxmode', 'neq', 'manual');

        $mform->addElement('select', 'type', get_string('paymenttype', 'paygw_stripe'), [
            'onetime' => get_string('paymenttype:onetime', 'paygw_stripe'),
            'subscription' => get_string('paymenttype:subscription', 'paygw_stripe'),
        ]);
        $mform->setType('type', PARAM_TEXT);
        $mform->setDefault('type', 'onetime');

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

        $mform->addElement('text', 'customsubscriptionintervalcount',
            get_string('customsubscriptionintervalcount', 'paygw_stripe'));
        $mform->setType('customsubscriptionintervalcount', PARAM_INT);
        $mform->setDefault('customsubscriptionintervalcount', 1);
        $mform->addHelpButton('customsubscriptionintervalcount', 'customsubscriptionintervalcount', 'paygw_stripe');
        $mform->addRule('customsubscriptionintervalcount', null, 'numeric', null, 'client');

        $mform->hideIf('customsubscriptioninterval', 'subscriptioninterval', 'neq', 'custom');
        $mform->hideIf('customsubscriptionintervalcount', 'subscriptioninterval', 'neq', 'custom');

        $mform->addElement('advcheckbox', 'anchorbilling', get_string('anchoredbilling', 'paygw_stripe'),
            get_string('anchoredbilling_help', 'paygw_stripe'));
        $mform->hideIf('anchorbilling', 'type', 'neq', 'subscription');

        $mform->addElement('advcheckbox', 'firstintervalfree', get_string('trialperiod', 'paygw_stripe'),
            get_string('trialperiod_help', 'paygw_stripe'));
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
    public static function validate_gateway_form(account_gateway $form,
        \stdClass $data, array $files, array &$errors): void {
        // When dynamic payment methods are enabled the manual list may legitimately be empty,
        // because the available methods are managed in the Stripe Dashboard instead.
        $usedynamic = !empty($data->usedynamicpaymentmethods);
        if ($data->enabled && (empty($data->apikey) || empty($data->secretkey) ||
                (!$usedynamic && empty($data->paymentmethods)))) {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }

        // The manual tax mode is meaningless without a tax rate ID to apply.
        if ($data->enabled && ($data->taxmode ?? 'none') === 'manual'
                && trim($data->manualtaxrate ?? '') === '') {
            $errors['manualtaxrate'] = get_string('manualtaxrate_required', 'paygw_stripe');
        }
    }
}
