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
 * Webhook for receiving events from Stripe.
 *
 * @package    paygw_stripe
 * @copyright  2023 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

use core_payment\helper;
use paygw_stripe\stripe_helper;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/.extlib/stripe-php/init.php');

$payload = @file_get_contents('php://input');

// Fetch gateway configuration using metadata values we set in the payment intent data.
$jsonpayload = json_decode($payload, true);
if ($jsonpayload == null) {
    http_response_code(400);
    exit();
}
$metadata = $jsonpayload['data']['object']['metadata'] ?? [];
// Objects not created by this plugin (e.g. Checkout Sessions of Payment Links, which also send
// checkout.session.completed) carry no Moodle metadata. Accept them without doing anything,
// otherwise Stripe would keep retrying the delivery and report the endpoint as failing.
if (empty($metadata['component']) || empty($metadata['paymentarea']) || empty($metadata['itemid'])) {
    http_response_code(202);
    exit();
}
// Events go to every endpoint of the Stripe account. Objects created by another Moodle site using the
// same account (e.g. a test site) name an item that may not exist here: accept them without doing
// anything instead of failing, otherwise Stripe retries them for days and may disable the endpoint.
if (!empty($metadata['moodle_site']) && $metadata['moodle_site'] !== $CFG->wwwroot) {
    http_response_code(202);
    exit();
}
try {
    $config = (object) helper::get_gateway_configuration($metadata['component'], $metadata['paymentarea'],
        $metadata['itemid'], 'stripe');
    $payable = helper::get_payable($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
} catch (moodle_exception $e) {
    // The item or its payment account no longer exists here (e.g. a deleted enrolment method).
    error_log('paygw_stripe: webhook for unknown item ' . $metadata['component'] . '/' . $metadata['paymentarea'] . '/' .
        $metadata['itemid'] . ' ignored: ' . $e->getMessage());
    http_response_code(202);
    exit();
}
$stripehelper = new stripe_helper($config->apikey, $config->secretkey);

// Validate payload using secret retrieved from webhook table.
$sigheader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

$webhook = $stripehelper->get_webhook($payable->get_account_id());
if ($webhook == null) {
    http_response_code(500);
    exit();
}
$endpointsecret = $webhook->secret;

try {
    $event = Webhook::constructEvent(
        $payload, $sigheader, $endpointsecret
    );

    if (!$stripehelper->process_stripe_event($event, $metadata)) {
        // Payload accepted but nothing to act upon.
        http_response_code(202);
        exit();
    }
} catch (UnexpectedValueException $e) {
    // Invalid payload.
    http_response_code(400);
    exit();
} catch (SignatureVerificationException $e) {
    // Invalid signature.
    http_response_code(400);
    exit();
}

http_response_code(200);
