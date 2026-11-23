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
 * Upgrade functions for paygw_stripe.
 *
 * @package    paygw_stripe
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

use core_payment\account;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\stripe_helper;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * Update all webhook events.
 *
 * @param array $events
 * @return void
 */
function paygw_stripe_update_webhooks(array $events) {
    global $DB;

    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'stripe']);
    foreach ($gateways as $gatewayrecord) {
        $account = new account($gatewayrecord->accountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if ($gateway != null) {
            $config = $gateway->get_configuration();
            try {
                $stripe = new StripeClient([
                    'api_key' => $config['secretkey'],
                    'stripe_version' => stripe_helper::$apiversion,
                ]);
                Stripe::setAppInfo(
                    'Moodle Stripe Payment Gateway',
                    get_config('paygw_stripe')->version,
                    'https://github.com/alexmorrisnz/moodle-paygw_stripe'
                );
                $webhooks = $DB->get_records('paygw_stripe_webhooks', ['paymentaccountid' => $account->get('id')]);
                foreach ($webhooks as $webhookrecord) {
                    $stripe->webhookEndpoints->update($webhookrecord->webhookid, ['enabled_events' => $events]);
                }
            } catch (Exception $ignored) {
                // Ignore errors, the api keys we are given may be wrong.
                continue;
            }
        }
    }
}

/**
 * Add standalone invoice events without resetting existing webhook subscriptions.
 * @return void
 */
function paygw_stripe_ensure_invoice_webhooks(): void {
    global $DB;
    foreach ($DB->get_records('payment_gateways', ['gateway' => 'stripe']) as $record) {
        try {
            $account = new account($record->accountid);
            $gateway = $account->get_gateways(false)['stripe'] ?? null;
            if (!$gateway) {
                continue;
            }
            $config = $gateway->get_configuration();
            if (empty($config['apikey']) || empty($config['secretkey'])) {
                continue;
            }
            $factory = new stripe_service_factory($config['apikey'], $config['secretkey']);
            $factory->webhook_service()->ensure_invoice_events((int)$record->accountid);
        } catch (Exception $ignored) {
            // Allow offline upgrades; start_payment repeats this check before using invoices.
            continue;
        }
    }
}

/**
 * Delete webhooks, they will be recreated when used later.
 *
 * @return void
 */
function paygw_stripe_delete_webhooks() {
    global $DB;

    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'stripe']);
    foreach ($gateways as $gatewayrecord) {
        $account = new account($gatewayrecord->accountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if ($gateway != null) {
            $config = $gateway->get_configuration();
            try {
                $stripe = new StripeClient([
                    'api_key' => $config['secretkey'],
                    'stripe_version' => stripe_helper::$apiversion,
                ]);
                Stripe::setAppInfo(
                    'Moodle Stripe Payment Gateway',
                    get_config('paygw_stripe')->version,
                    'https://github.com/alexmorrisnz/moodle-paygw_stripe'
                );
                $webhooks = $DB->get_records('paygw_stripe_webhooks', ['paymentaccountid' => $account->get('id')]);
                foreach ($webhooks as $webhookrecord) {
                    $stripe->webhookEndpoints->delete($webhookrecord->webhookid);
                    $DB->delete_records('paygw_stripe_webhooks', ['id' => $webhookrecord->id]);
                }
            } catch (Exception $ignored) {
                // Ignore errors, the api keys we are given may be wrong.
                continue;
            }
        }
    }
}

/**
 * Recreate webhooks for API upgrades.
 *
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 */
function paygw_stripe_recreate_webhooks() {
    global $DB;

    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'stripe']);
    foreach ($gateways as $gatewayrecord) {
        $account = new account($gatewayrecord->accountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if ($gateway != null) {
            $config = $gateway->get_configuration();
            if (!is_string($config['apikey']) || !is_string($config['secretkey'])) {
                continue;
            }
            try {
                $factory = new stripe_service_factory($config['apikey'], $config['secretkey']);
                $webhookservice = $factory->webhook_service();
                $webhookservice->delete_webhook($account->get('id'));
                $webhookservice->create_webhook($account->get('id'));
            } catch (Exception $ignored) {
                // Ignore errors, the api keys we are given may be wrong.
                continue;
            }
        }
    }
}

/**
 * Move payment methods from plugin setting to Stripe Payment Methods configuration.
 *
 * @return void
 */
function paygw_stripe_move_payment_methods() {
    global $DB;

    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'stripe']);
    foreach ($gateways as $gatewayrecord) {
        $account = new account($gatewayrecord->accountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if ($gateway != null) {
            $config = $gateway->get_configuration();
            if (!is_string($config['apikey']) || !is_string($config['secretkey'])) {
                continue;
            }
            try {
                $factory = new stripe_service_factory($config['apikey'], $config['secretkey']);
                $paymentmethodservice = $factory->payment_method_config_service();
                // Fetch payment methods for payment account.
                $paymentmethods = $config['paymentmethods'] ?? [];
                if (empty($paymentmethods)) {
                    continue;
                }
                // Create stripe payment method configuration.
                $configid = $paymentmethodservice->create_payment_method_config($account->get_formatted_name(), $paymentmethods);

                // Update payment account setting to use stripe payment method configuration.
                $config['paymentmethodconfiguration'] = $configid;
                $gateway->set('config', json_encode($config));
                $gateway->update();
            } catch (Exception $ignored) {
                // Ignore errors, the api keys we are given may be wrong.
                continue;
            }
        }
    }
}

/**
 * Brings a site running the WSB Merito fork (1.39, version 2026110103) to the schema of this version.
 *
 * The fork's version numbers are higher than the upstream upgrade steps 2025072800-2026102900, so Moodle
 * skips those steps on such a site. This function applies the same schema changes, each one only when it
 * is missing, so it is safe on any site (fork, upstream or fresh) and when run again.
 * Nothing is dropped and no existing row is deleted; existing values are kept.
 * Webhook endpoints and Stripe payment method configurations are not touched here.
 *
 * @return string[] What was changed (for the upgrade log).
 */
function paygw_stripe_upgrade_bridge_schema(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $done = [];

    // Payment sessions: paygw_stripe_intents -> paygw_stripe_checkout_sessions (rows are kept).
    $intents = new xmldb_table('paygw_stripe_intents');
    $sessions = new xmldb_table('paygw_stripe_checkout_sessions');
    if ($dbman->table_exists($intents) && !$dbman->table_exists($sessions)) {
        $field = new xmldb_field('checkoutsessionid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'userid');
        if (!$dbman->field_exists($intents, $field)) {
            $dbman->add_field($intents, $field);
        }
        $index = new xmldb_index('checkoutsessionid', XMLDB_INDEX_UNIQUE, ['checkoutsessionid']);
        if (!$dbman->index_exists($intents, $index)) {
            $dbman->add_index($intents, $index);
        }
        $intentindex = new xmldb_index('paymentintent', XMLDB_INDEX_UNIQUE, ['paymentintent']);
        if ($dbman->index_exists($intents, $intentindex)) {
            $dbman->drop_index($intents, $intentindex);
        }
        $dbman->change_field_notnull(
            $intents,
            new xmldb_field('paymentintent', XMLDB_TYPE_CHAR, '100', null, null, null, null)
        );
        $dbman->rename_table($intents, 'paygw_stripe_checkout_sessions');
        $done[] = 'paygw_stripe_intents renamed to paygw_stripe_checkout_sessions';
    }

    // Delivery flags: rows that existed before this upgrade were already delivered.
    foreach (['paygw_stripe_checkout_sessions' => 'productid', 'paygw_stripe_subscriptions' => 'priceid'] as $t => $after) {
        $table = new xmldb_table($t);
        $field = new xmldb_field('delivered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', $after);
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $DB->set_field($t, 'delivered', 1);
            $done[] = "$t.delivered added (existing rows = 1)";
        }
    }

    // Customers: billing identity managed in the Stripe portal.
    $table = new xmldb_table('paygw_stripe_customers');
    $field = new xmldb_field('billingmanaged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
        $done[] = 'paygw_stripe_customers.billingmanaged added';
    }

    // Invoices (new upstream table) – created from install.xml with all its current fields.
    if (!$dbman->table_exists(new xmldb_table('paygw_stripe_invoices'))) {
        $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'paygw_stripe_invoices');
        $done[] = 'paygw_stripe_invoices created';
    }

    // Subscriptions: payment item stored with each subscription (fork change 2026110100).
    $table = new xmldb_table('paygw_stripe_subscriptions');
    $fields = [
        new xmldb_field('component', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'delivered'),
        new xmldb_field('paymentarea', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'component'),
        new xmldb_field('itemid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'paymentarea'),
    ];
    $added = false;
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $added = true;
        }
    }
    if ($added) {
        foreach ($DB->get_records_select('paygw_stripe_subscriptions', 'component IS NULL') as $sub) {
            $products = $DB->get_records('paygw_stripe_products', ['productid' => $sub->productid], 'id DESC', '*', 0, 1);
            if ($product = reset($products)) {
                $sub->component = $product->component;
                $sub->paymentarea = $product->paymentarea;
                $sub->itemid = $product->itemid;
                $DB->update_record('paygw_stripe_subscriptions', $sub);
            }
        }
        $done[] = 'paygw_stripe_subscriptions.component/paymentarea/itemid added and filled from products';
    }
    return $done;
}
