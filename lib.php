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
 * Lib functions.
 *
 * @package    paygw_stripe
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User profile page callback.
 *
 * Used add a section about the subscriptions.
 *
 * @param \core_user\output\myprofile\tree $tree My profile tree where the setting will be added.
 * @param stdClass $user The user object.
 * @param bool $iscurrentuser Is this the current user viewing
 * @return void Return if the mobile web services setting is disabled or if not the current user.
 */
function paygw_stripe_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser) {
    global $USER, $DB;

    // WSB Merito: nothing for a guest (no subscriptions, no Stripe customer).
    if (!$iscurrentuser || !isloggedin() || isguestuser()) {
        return;
    }

    $tree->add_category(new core_user\output\myprofile\category(
        'paygw_stripe',
        get_string('profilecat', 'paygw_stripe'),
        'loginactivity'
    ));
    $tree->add_node(new core_user\output\myprofile\node(
        'paygw_stripe',
        'cancelsubscriptions',
        get_string('cancelsubscriptions', 'paygw_stripe'),
        null,
        new moodle_url('/payment/gateway/stripe/subscriptions.php')
    ));

    // WSB Merito: link to the Stripe customer portal (invoices and receipts). It only needs the user's Stripe customer
    // and a key of the Stripe account, so any Stripe payment account will do. The account of the latest payment is
    // tried first; its course or enrolment method may have been deleted since, so the other enabled accounts follow.
    $portalurl = null;
    if ($DB->record_exists('payments', ['userid' => $USER->id, 'gateway' => 'stripe'])) {
        $configs = [];
        foreach ($DB->get_records('payments', ['userid' => $USER->id, 'gateway' => 'stripe'], 'id DESC', '*', 0, 1) as $payment) {
            try {
                $configs[] = (object) \core_payment\helper::get_gateway_configuration(
                    $payment->component,
                    $payment->paymentarea,
                    $payment->itemid,
                    $payment->gateway
                );
            } catch (\moodle_exception $e) {
                debugging('paygw_stripe: no gateway configuration for the latest payment ' . $payment->id . ': ' .
                    $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $gateways = $DB->get_records_sql(
            "SELECT pg.id, pg.config
               FROM {payment_gateways} pg
               JOIN {payment_accounts} pa ON pa.id = pg.accountid
              WHERE pg.gateway = 'stripe' AND pg.enabled = 1 AND pa.enabled = 1 AND pa.archived = 0
           ORDER BY pg.id"
        );
        foreach ($gateways as $gateway) {
            $configs[] = (object) json_decode($gateway->config, true);
        }
        $tried = [];
        foreach ($configs as $config) {
            if (empty($config->secretkey) || isset($tried[$config->secretkey])) {
                continue;
            }
            $tried[$config->secretkey] = true;
            $factory = new \paygw_stripe\local\service\stripe_service_factory($config->apikey ?? '', $config->secretkey);
            if ($portalurl = $factory->customer_service()->get_customer_portal_url((int) $USER->id)) {
                break;
            }
        }
    }
    if ($portalurl) {
        $tree->add_node(new core_user\output\myprofile\node(
            'paygw_stripe',
            'stripeinvoices',
            get_string('stripeinvoices', 'paygw_stripe'),
            null,
            new moodle_url($portalurl)
        ));
    }
}
