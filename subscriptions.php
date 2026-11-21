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
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_stripe\local\repository\product_repository;
use paygw_stripe\local\repository\subscription_repository;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\subscription_service;
use paygw_stripe\local\service\wsb_purchase_guard;

require_once(__DIR__ . '/../../../config.php');

require_login();

$action = optional_param('action', false, PARAM_TEXT);
$subid = optional_param('subscriptionid', null, PARAM_INT);

$PAGE->set_url('/payment/gateway/stripe/subscriptions.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('subscriptions', 'paygw_stripe'));
$PAGE->set_heading(get_string('subscriptions', 'paygw_stripe'));

$repo = new subscription_repository();
$productrepo = new product_repository();

if ($subid != null) {
    $subscription = $repo->find_by_id($subid);
    if (!$subscription || $subscription->userid !== (int)$USER->id) {
        throw new \moodle_exception('subscriptioninvalid', 'paygw_stripe');
    }
    if ($action == 'cancel' || $action == 'resume') {
        // Cancelling only from the confirmation form (cancel.php), resuming from the link on this page.
        require_sesskey();
    }

    // WSB Merito: the payment item stored with the subscription (product mapping only for older records), cancel at
    // the end of the paid period, resume, and an understandable message instead of an exception page.
    $returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
    try {
        $product = wsb_purchase_guard::get_subscription_item($DB->get_record('paygw_stripe_subscriptions', ['id' => $subid]));
        if (!$product) {
            throw new moodle_exception('subscriptionactionerror', 'paygw_stripe');
        }
        $config = (object) helper::get_gateway_configuration(
            $product->component,
            $product->paymentarea,
            $product->itemid,
            'stripe'
        );
        $factory = new stripe_service_factory($config->apikey, $config->secretkey);
        $subscriptionservice = $factory->subscription_service();

        if ($action == 'cancel') {
            [$outcome, $endsat] = $subscriptionservice->schedule_cancellation($subscription);
            if ($outcome === 'scheduled') {
                $message = get_string(
                    'subscriptioncancelscheduled',
                    'paygw_stripe',
                    subscription_service::format_subscription_date($endsat)
                );
            } else if ($outcome === 'cancelled') {
                $message = get_string('subscriptioncancelled', 'paygw_stripe');
            } else {
                $message = get_string('subscriptionalreadycancelled', 'paygw_stripe');
            }
            redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action == 'resume') {
            $renewsat = $subscriptionservice->resume_subscription($subscription);
            if ($renewsat) {
                redirect($returnurl, get_string(
                    'subscriptionresumed',
                    'paygw_stripe',
                    subscription_service::format_subscription_date($renewsat)
                ), null, \core\output\notification::NOTIFY_SUCCESS);
            }
            redirect(
                $returnurl,
                get_string('subscriptionnotresumable', 'paygw_stripe'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        } else if ($action == 'portal') {
            $subscriptionservice->load_portal($subscription);
        }
    } catch (\Stripe\Exception\ApiErrorException | moodle_exception $e) {
        debugging('paygw_stripe: action "' . $action . '" on subscription ' . $subscription->subscriptionid . ' failed: ' .
            $e->getMessage(), DEBUG_DEVELOPER);
        redirect($returnurl, get_string('subscriptionactionerror', 'paygw_stripe'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();

echo html_writer::tag(
    'p',
    get_string('subscriptionssubheading', 'paygw_stripe'),
    ['class' => 'paygw_stripe-subscriptions-subheading']
);

$table = new \html_table();
$table->head = [
    get_string('product', 'paygw_stripe'),
    get_string('fee', 'paygw_stripe'),
    get_string('scheduledrenewal', 'paygw_stripe'),
    get_string('status', 'paygw_stripe'),
    '',
    '',
];

$subscriptions = $repo->find_all_by_userid((int)$USER->id);

$table->data = [];

foreach ($subscriptions as $subscription) {
    $product = wsb_purchase_guard::get_subscription_item($DB->get_record('paygw_stripe_subscriptions',
        ['id' => $subscription->id]));
    // Switching API keys can lead to products in the Moodle DB not matching what exists in Stripe, ignore and move on.
    if ($product == null) {
        continue;
    }
    try {
        $config = (object) helper::get_gateway_configuration(
            $product->component,
            $product->paymentarea,
            $product->itemid,
            'stripe'
        );
    } catch (moodle_exception $e) {
        // WSB Merito: the payment account or the enrolment instance no longer exists – skip the row, not the page.
        debugging('paygw_stripe: no gateway configuration for subscription ' . $subscription->subscriptionid . ': ' .
            $e->getMessage(), DEBUG_DEVELOPER);
        continue;
    }
    $factory = new stripe_service_factory($config->apikey, $config->secretkey);
    $subscriptionservice = $factory->subscription_service();
    $row = $subscriptionservice->get_subscription_table_data($subscription);
    if ($row != null) {
        $table->data[] = $row;
    }
}

echo \html_writer::table($table);

echo $OUTPUT->footer();
