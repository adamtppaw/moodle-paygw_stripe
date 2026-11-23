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
 * Process payment, deliver the order to the user.
 *
 * @package    paygw_stripe
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\wsb_purchase_guard;
use paygw_stripe\stripe_helper;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/.extlib/stripe-php/init.php');

require_login();

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);
$sessionid = required_param('session_id', PARAM_TEXT);

$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'stripe');

$factory = new stripe_service_factory($config->apikey, $config->secretkey);
$checkoutservice = $factory->checkout_service();
$stripehelper = new stripe_helper($config->apikey, $config->secretkey);

// WSB Merito: an unknown session ID (or one Stripe cannot return) is refused like a session of another purchase.
try {
    $sessionmode = $checkoutservice->get_sessionmode($sessionid);
} catch (\Stripe\Exception\ApiErrorException $e) {
    $sessionmode = null;
}
$mismatch = function () {
    redirect(new moodle_url('/'), get_string('sessionmismatch', 'paygw_stripe'), null,
        \core\output\notification::NOTIFY_ERROR);
};

if ($sessionmode === 'subscription') {
    $subscriptionservice = $factory->subscription_service();
    if (!$subscriptionservice->is_session_bound_to_request($sessionid, (int)$USER->id, $component, $paymentarea, $itemid)) {
        $mismatch();
    }
    try {
        // False when it was delivered already (e.g. by the webhook) – the buyer still sees the success page.
        $subscriptionservice->fulfil_subscription($sessionid, (int)$USER->id, $component, $paymentarea, $itemid);
    } catch (moodle_exception $e) {
        redirect(new moodle_url('/'), get_string('subscriptionerror', 'paygw_stripe'));
    }
    $url = wsb_purchase_guard::get_return_url($component, $paymentarea, $itemid, (int) $USER->id);
    redirect($url, get_string('subscriptionsuccessful', 'paygw_stripe'), 0, 'success');
} else if ($sessionmode === 'payment') {
    if (!$checkoutservice->is_session_bound_to_request($sessionid, (int)$USER->id, $component, $paymentarea, $itemid)) {
        $mismatch();
    }
    if ($checkoutservice->is_paid($sessionid)) {
        // Delivered once, shared with the checkout.session.completed webhook (which may have come first).
        $stripehelper->deliver_checkout_once($sessionid, $component, $paymentarea, $itemid, (int)$USER->id);
        $url = wsb_purchase_guard::get_return_url($component, $paymentarea, $itemid, (int) $USER->id);
        redirect($url, get_string('paymentsuccessful', 'paygw_stripe'), 0, 'success');
    } else if ($checkoutservice->is_pending($sessionid)) {
        $checkoutservice->save_payment_status($sessionid);
        redirect(new moodle_url('/'), get_string('paymentpending', 'paygw_stripe'));
    }
} else {
    $mismatch();
}

// Neither paid nor pending (e.g. the session was left unpaid or expired): say so and go back to the course.
$url = wsb_purchase_guard::get_return_url($component, $paymentarea, $itemid, (int) $USER->id, false);
redirect($url, get_string('paymentnotcompleted', 'paygw_stripe'), null, \core\output\notification::NOTIFY_WARNING);
