<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * WSB Merito: move the Stripe webhook endpoints of every payment account to the plugin's API version.
 *
 * Stripe cannot change the API version of an existing endpoint, so for each payment account:
 *   1. a new endpoint is created (same URL, the plugin's events and API version),
 *   2. its id and signing secret replace the old ones in paygw_stripe_webhooks,
 *   3. the old endpoint is deleted.
 * While both endpoints exist Stripe sends each event to both; the one whose secret is not stored yet gets 400 and is
 * retried, so no event is lost. Accounts whose endpoint already uses the plugin's version are skipped.
 *
 *   php payment/gateway/stripe/cli/wsb_swap_webhooks.php             (dry run – shows the endpoints and the plan)
 *   php payment/gateway/stripe/cli/wsb_swap_webhooks.php --execute
 *
 * Back to release 1.39: delete the endpoints in the Stripe Dashboard; 1.39 creates new ones in its own version on the
 * next purchase.
 *
 * @package    paygw_stripe
 * @copyright  2026 WSB Merito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

use core_payment\account;
use paygw_stripe\stripe_helper;
use Stripe\StripeClient;

[$options, $unrecognized] = cli_get_params(
    ['execute' => false, 'url' => '', 'account' => 0, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || $unrecognized) {
    echo "Move Stripe webhook endpoints to API version " . stripe_helper::$apiversion . ".\n" .
        "  --execute     apply (without it: dry run)\n" .
        "  --account=ID  only this payment account\n" .
        "  --url=URL     endpoint URL (default: this site's webhook.php; for tests behind a tunnel)\n";
    exit(0);
}

$url = $options['url'] ?: $CFG->wwwroot . '/payment/gateway/stripe/webhook.php';
$events = [
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed',
    'customer.subscription.deleted',
    'customer.subscription.updated',
    'invoice.paid',
    'invoice.voided',
];
mtrace('Plugin API version: ' . stripe_helper::$apiversion . ', URL: ' . $url);

$failures = 0;
foreach ($DB->get_records('payment_gateways', ['gateway' => 'stripe'], 'accountid') as $gatewayrecord) {
    $accountid = (int) $gatewayrecord->accountid;
    if ($options['account'] && $accountid !== (int) $options['account']) {
        continue;
    }
    $config = json_decode($gatewayrecord->config ?? '', true) ?: [];
    if (empty($config['secretkey']) || !is_string($config['secretkey'])) {
        mtrace("account $accountid: no API key – skipped");
        continue;
    }
    $name = (new account($accountid))->get_formatted_name();
    $stripe = new StripeClient(['api_key' => $config['secretkey'], 'stripe_version' => stripe_helper::$apiversion]);
    $record = $DB->get_record('paygw_stripe_webhooks', ['paymentaccountid' => $accountid]);
    $old = null;
    if ($record) {
        try {
            $old = $stripe->webhookEndpoints->retrieve($record->webhookid);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
        }
    }
    $state = $old ? "$old->id, version " . ($old->api_version ?? '?') . ", $old->status, $old->url"
        : ($record ? "$record->webhookid (missing in Stripe)" : 'none stored');
    mtrace("account $accountid ($name): $state");
    if ($old && ($old->api_version ?? '') === stripe_helper::$apiversion && $old->url === $url) {
        mtrace('  already on the plugin version – skipped');
        continue;
    }
    if (!$options['execute']) {
        mtrace('  WOULD: create a new endpoint, store its secret' . ($old ? ", delete $old->id" : ''));
        continue;
    }
    try {
        $params = ['url' => $url, 'enabled_events' => $events, 'api_version' => stripe_helper::$apiversion];
        if (!empty($old->description)) {
            $params['description'] = $old->description;
        }
        if (!empty($old->metadata) && count($old->metadata->toArray())) {
            $params['metadata'] = $old->metadata->toArray();
        }
        $new = $stripe->webhookEndpoints->create($params);
        if ($record) {
            $record->webhookid = $new->id;
            $record->secret = $new->secret;
            $DB->update_record('paygw_stripe_webhooks', $record);
        } else {
            $DB->insert_record('paygw_stripe_webhooks', (object) ['paymentaccountid' => $accountid,
                'webhookid' => $new->id, 'secret' => $new->secret]);
        }
        mtrace("  DONE: new endpoint $new->id (version $new->api_version) stored");
        if ($old) {
            $stripe->webhookEndpoints->delete($old->id);
            mtrace("  DONE: old endpoint $old->id deleted");
        }
    } catch (\Throwable $e) {
        $failures++;
        mtrace('  FAILED: ' . get_class($e) . ': ' . $e->getMessage());
    }
}
if ($failures) {
    cli_error("$failures account(s) failed – check the output above and the endpoints in the Stripe Dashboard.");
}
mtrace($options['execute'] ? 'Done.' : 'Dry run. Add --execute to apply.');
