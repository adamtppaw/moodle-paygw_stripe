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

$subid = optional_param('subscriptionid', null, PARAM_INT);

$PAGE->set_url('/payment/gateway/stripe/cancel.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('subscriptions', 'paygw_stripe'));
$PAGE->set_heading(get_string('subscriptions', 'paygw_stripe'));

$subscription = $DB->get_record('paygw_stripe_subscriptions', ['id' => $subid], '*', MUST_EXIST);
if ((int) $subscription->userid !== (int) $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('cancelsubscription', 'paygw_stripe'));
}

// Tell the user until when they keep access: cancelling ends the subscription at the end of the
// paid period. If Stripe cannot be reached, fall back to the generic question.
$message = get_string('cancelsubscriptionconfirm', 'paygw_stripe');
if ($product = $DB->get_record('paygw_stripe_products', ['productid' => $subscription->productid])) {
    try {
        $config = (object) helper::get_gateway_configuration($product->component, $product->paymentarea, $product->itemid,
            'stripe');
        $stripehelper = new stripe_helper($config->apikey, $config->secretkey);
        $periodend = $stripehelper->get_current_period_end($subscription);
        if ($periodend) {
            $message = get_string('cancelsubscriptionconfirmuntil', 'paygw_stripe',
                stripe_helper::format_subscription_date($periodend));
        }
    } catch (\Stripe\Exception\ApiErrorException | moodle_exception $e) {
        error_log('paygw_stripe: cannot read subscription ' . $subscription->subscriptionid . ' for the confirmation: ' .
            $e->getMessage());
    }
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('cancelsubscription', 'paygw_stripe'));

$returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
$cancelurl = new moodle_url('/payment/gateway/stripe/subscriptions.php', ['subscriptionid' => $subid, 'action' => 'cancel']);
$cancelbutton = new single_button($cancelurl, get_string('confirm'), 'post');

echo $OUTPUT->confirm($message, $cancelbutton, $returnurl);

echo $OUTPUT->footer();
