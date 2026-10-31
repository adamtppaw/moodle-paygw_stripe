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
 * Subscription list page.
 *
 * @package    paygw_stripe
 * @author     Alex Morris <alex@navra.nz>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_stripe\stripe_helper;

require('../../../config.php');

require_login();

$action = optional_param('action', false, PARAM_TEXT);
$subid = optional_param('subscriptionid', null, PARAM_INT);

$PAGE->set_url('/payment/gateway/stripe/subscriptions.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('subscriptions', 'paygw_stripe'));
$PAGE->set_heading(get_string('subscriptions', 'paygw_stripe'));

if ($subid != null) {
    $subscription = $DB->get_record('paygw_stripe_subscriptions', ['id' => $subid], '*', MUST_EXIST);
    // Users can manage only their own subscriptions. Without this check any logged-in user could
    // cancel someone else's subscription (or open their customer portal) by changing the id.
    if ((int) $subscription->userid !== (int) $USER->id) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('cancelsubscription', 'paygw_stripe'));
    }
    if ($action == 'cancel' || $action == 'resume') {
        // These change data: cancelling only from the confirmation form (cancel.php), resuming
        // from the link on this page; never from a bare link without sesskey.
        require_sesskey();
    }
    $product = $DB->get_record('paygw_stripe_products', ['productid' => $subscription->productid]);
    $returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
    try {
        if (!$product) {
            throw new moodle_exception('subscriptionactionerror', 'paygw_stripe');
        }
        $config = (object) helper::get_gateway_configuration($product->component, $product->paymentarea, $product->itemid,
            'stripe');
        $stripehelper = new stripe_helper($config->apikey, $config->secretkey);

        if ($action == 'cancel') {
            // Cancel at the end of the paid period: access stays until then (see schedule_cancellation()).
            [$outcome, $endsat] = $stripehelper->schedule_cancellation($subscription);
            if ($outcome === 'scheduled') {
                $message = get_string('subscriptioncancelscheduled', 'paygw_stripe',
                    stripe_helper::format_subscription_date($endsat));
            } else if ($outcome === 'cancelled') {
                $message = get_string('subscriptioncancelled', 'paygw_stripe');
            } else {
                $message = get_string('subscriptionalreadycancelled', 'paygw_stripe');
            }
            redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action == 'resume') {
            $renewsat = $stripehelper->resume_subscription($subscription);
            if ($renewsat) {
                redirect($returnurl, get_string('subscriptionresumed', 'paygw_stripe',
                    stripe_helper::format_subscription_date($renewsat)), null, \core\output\notification::NOTIFY_SUCCESS);
            }
            redirect($returnurl, get_string('subscriptionnotresumable', 'paygw_stripe'), null,
                \core\output\notification::NOTIFY_WARNING);
        } else if ($action == 'portal') {
            $stripehelper->load_portal($subscription);
        }
    } catch (\Stripe\Exception\ApiErrorException | moodle_exception $e) {
        // Show an understandable message instead of an exception page and log the details.
        error_log('paygw_stripe: action "' . $action . '" on subscription ' . $subscription->subscriptionid .
            ' failed: ' . $e->getMessage());
        redirect($returnurl, get_string('subscriptionactionerror', 'paygw_stripe'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();

echo html_writer::tag('p', get_string('subscriptionssubheading', 'paygw_stripe'),
    ['class' => 'paygw_stripe-subscriptions-subheading']);

$table = new \html_table();
$table->head = [
    get_string('product', 'paygw_stripe'),
    get_string('fee', 'paygw_stripe'),
    get_string('scheduledrenewal', 'paygw_stripe'),
    get_string('status', 'paygw_stripe'),
    '',
    '',
];

$subscriptions = $DB->get_records('paygw_stripe_subscriptions', ['userid' => $USER->id]);

$table->data = [];

foreach ($subscriptions as $subscription) {
    $product = $DB->get_record('paygw_stripe_products', ['productid' => $subscription->productid]);
    if (!$product) {
        // The product mapping was removed (e.g. archived product); nothing to show or manage.
        continue;
    }
    try {
        $config = (object) helper::get_gateway_configuration($product->component, $product->paymentarea, $product->itemid,
            'stripe');
    } catch (moodle_exception $e) {
        // The payment account or the enrolment instance no longer exists.
        error_log('paygw_stripe: no gateway configuration for subscription ' . $subscription->subscriptionid . ': ' .
            $e->getMessage());
        continue;
    }
    $stripehelper = new stripe_helper($config->apikey, $config->secretkey);
    $row = $stripehelper->get_subscription_table_data($subscription);
    if ($row != null) {
        $table->data[] = $row;
    }
}

echo \html_writer::table($table);

echo $OUTPUT->footer();
