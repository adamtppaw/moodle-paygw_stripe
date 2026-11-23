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
 * @copyright  Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

use core_payment\helper;
use paygw_stripe\local\service\stripe_service_factory;
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
// Standalone invoices resolve their account and purchase from the local record.
// Do not route subscription/Checkout invoices using their metadata.
if (in_array($jsonpayload['type'] ?? '', ['invoice.paid', 'invoice.voided'], true)) {
    if (empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
        http_response_code(400);
        exit();
    }
    try {
        $handler = new \paygw_stripe\local\service\invoice_webhook_handler();
        $handled = $handler->handle($payload, $_SERVER['HTTP_STRIPE_SIGNATURE']);
        http_response_code($handled ? 200 : 202);
    } catch (SignatureVerificationException $e) {
        // Stripe broadcasts to all registered endpoints, each with its own secret.
        http_response_code(202);
    } catch (UnexpectedValueException $e) {
        http_response_code(400);
    } catch (\Throwable $e) {
        // A retry is essential if payment storage or course delivery failed.
        http_response_code(500);
        debugging('paygw_stripe: invoice webhook failed (' . get_class($e) . '), event ' .
            clean_param($jsonpayload['id'] ?? '', PARAM_ALPHANUMEXT), DEBUG_DEVELOPER);
    }
    exit();
}

if (!isset($jsonpayload['data']['object']['metadata'])) {
    http_response_code(202);
    exit();
}
if (
    !isset($jsonpayload['data']['object']['metadata']['component']) ||
    !isset($jsonpayload['data']['object']['metadata']['paymentarea']) ||
    !isset($jsonpayload['data']['object']['metadata']['itemid'])
) {
    http_response_code(202);
    exit();
}

$metadata = $jsonpayload['data']['object']['metadata'];
// WSB Merito: events go to every endpoint of the Stripe account. Objects of another Moodle site using the same account
// (e.g. a test site) name an item that may not exist here – accept them without doing anything instead of failing,
// otherwise Stripe retries them for days and may disable the endpoint. The same for an item deleted here.
if (!empty($metadata['moodle_site']) && $metadata['moodle_site'] !== $CFG->wwwroot) {
    http_response_code(202);
    exit();
}
try {
    $config = (object) helper::get_gateway_configuration($metadata['component'], $metadata['paymentarea'],
        $metadata['itemid'], 'stripe');
    $payable = helper::get_payable($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
} catch (moodle_exception $e) {
    debugging('paygw_stripe: webhook for unknown item ' . $metadata['component'] . '/' . $metadata['paymentarea'] . '/' .
        $metadata['itemid'] . ' ignored: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http_response_code(202);
    exit();
}

$factory = new stripe_service_factory($config->apikey, $config->secretkey);

$stripehelper = new stripe_helper($config->apikey, $config->secretkey);

if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    http_response_code(400);
    exit();
}

$sigheader = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

// Validate payload using secret retrieved from webhook table.
$webhookservice = $factory->webhook_service();
$webhook = $webhookservice->get_webhook($payable->get_account_id());
if ($webhook == null) {
    http_response_code(500);
    exit();
}
$endpointsecret = $webhook->secret;

try {
    $event = Webhook::constructEvent(
        $payload,
        $sigheader,
        $endpointsecret
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
    // Stripe broadcasts each event to all endpoints of the account, one per payment account, each with its own secret.
    // WSB Merito: accept (202, nothing processed) only a signature made with the secret of another of our endpoints –
    // that endpoint's delivery handles the event. A signature matching none of our secrets is forged or broken: 400.
    global $DB;
    foreach ($DB->get_fieldset_select('paygw_stripe_webhooks', 'secret', 'secret <> :own', ['own' => $endpointsecret]) as $secret) {
        try {
            Webhook::constructEvent($payload, $sigheader, $secret);
            http_response_code(202);
            exit();
        } catch (SignatureVerificationException $ignored) {
            continue;
        }
    }
    http_response_code(400);
    exit();
}

http_response_code(200);
