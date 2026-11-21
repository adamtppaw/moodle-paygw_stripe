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
use paygw_stripe\local\model\subscription;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\subscription_service;
use paygw_stripe\local\service\wsb_purchase_guard;

require('../../../config.php');

require_login();

$subid = optional_param('subscriptionid', null, PARAM_INT);

$PAGE->set_url('/payment/gateway/stripe/cancel.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('subscriptions', 'paygw_stripe'));
$PAGE->set_heading(get_string('subscriptions', 'paygw_stripe'));

$record = $DB->get_record('paygw_stripe_subscriptions', ['id' => $subid], '*', MUST_EXIST);
if ((int) $record->userid !== (int) $USER->id) {
    throw new moodle_exception('subscriptioninvalid', 'paygw_stripe');
}

// WSB Merito: tell the user until when they keep access (cancelling ends the subscription at the end of the paid
// period). If Stripe cannot be reached, fall back to the generic question.
$message = get_string('cancelsubscriptionconfirmgeneric', 'paygw_stripe');
if ($item = wsb_purchase_guard::get_subscription_item($record)) {
    try {
        $config = (object) helper::get_gateway_configuration($item->component, $item->paymentarea, $item->itemid, 'stripe');
        $factory = new stripe_service_factory($config->apikey, $config->secretkey);
        $periodend = $factory->subscription_service()->get_current_period_end(subscription::from_record($record));
        if ($periodend) {
            $message = get_string(
                'cancelsubscriptionconfirmuntil',
                'paygw_stripe',
                subscription_service::format_subscription_date($periodend)
            );
        }
    } catch (\Stripe\Exception\ApiErrorException | moodle_exception $e) {
        debugging('paygw_stripe: cannot read subscription ' . $record->subscriptionid . ' for the confirmation: ' .
            $e->getMessage(), DEBUG_DEVELOPER);
    }
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('cancelsubscription', 'paygw_stripe'));

$returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
$cancelurl = new moodle_url('/payment/gateway/stripe/subscriptions.php', ['subscriptionid' => $subid, 'action' => 'cancel']);
$cancelbutton = new single_button($cancelurl, get_string('confirm'), 'post');

echo $OUTPUT->confirm($message, $cancelbutton, $returnurl);

echo $OUTPUT->footer();
