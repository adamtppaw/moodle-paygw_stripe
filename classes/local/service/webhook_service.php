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

declare(strict_types=1);

namespace paygw_stripe\local\service;

use paygw_stripe\local\model\webhook;
use paygw_stripe\local\repository\webhook_repository;
use paygw_stripe\stripe_helper;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

/**
 * Webhook service.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * @var webhook_repository The webhook repository.
     */
    private $webhookrepository;

    /**
     * Webhook service constructor.
     *
     * @param StripeClient $stripe The Stripe API client.
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->webhookrepository = new webhook_repository();
    }

    /**
     * Find and return webhook endpoint if it exists.
     * Retrieve secret from Moodle database and add to webhook object.
     *
     * @param int $paymentaccountid
     * @return WebhookEndpoint|null
     * @throws ApiErrorException|\dml_exception
     */
    public function get_webhook(int $paymentaccountid): ?WebhookEndpoint {
        if (!($record = $this->webhookrepository->find_by_paymentaccountid($paymentaccountid))) {
            return null;
        }

        try {
            $webhook = $this->stripe->webhookEndpoints->retrieve($record->webhookid);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
            // The endpoint was deleted in Stripe (e.g. in the Dashboard): forget it, so that create_webhook()
            // registers a new one instead of every purchase failing.
            $this->webhookrepository->delete($record);
            return null;
        }
        // Webhook still exists, lets set the secret and return.
        $webhook->secret = $record->secret;
        return $webhook;
    }

    /**
     * Create webhook for given account id if none already exists.
     *
     * @param int $paymentaccountid
     * @return bool True if webhook was created
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_webhook(int $paymentaccountid): bool {
        global $CFG;

        if ($this->get_webhook($paymentaccountid) != null) {
            return false;
        }

        $webhook = $this->stripe->webhookEndpoints->create([
            'url' => $CFG->wwwroot . '/payment/gateway/stripe/webhook.php',
            'enabled_events' => [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'customer.subscription.deleted',
                'customer.subscription.updated',
                'invoice.paid',
                'invoice.voided',
            ],
            'api_version' => stripe_helper::$apiversion,
        ]);

        $record = new webhook(
            null,
            $paymentaccountid,
            $webhook->id,
            $webhook->secret
        );
        $this->webhookrepository->save($record);

        return true;
    }

    /**
     * Make sure a payment account has a webhook endpoint after its API keys changed.
     *
     * New keys of the same Stripe account (e.g. a rotated key) can still retrieve the stored endpoint, so it is kept
     * together with its signing secret. Keys of another Stripe account cannot see it (404): get_webhook() forgets the
     * stored record and an endpoint is created in the new account. The previous endpoint is never deleted here, because
     * the old keys may still serve another site (e.g. a copy of this database on a test server) and its endpoint with
     * them.
     *
     * @param int $paymentaccountid
     * @return bool True if a new endpoint was created
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function update_webhook_for_new_keys(int $paymentaccountid): bool {
        return $this->create_webhook($paymentaccountid);
    }

    /**
     * Add invoice events to existing endpoints without removing any subscriptions
     * or changing their API version/signing secret. Repair failed upgrade calls.
     * @param int $paymentaccountid
     */
    public function ensure_invoice_events(int $paymentaccountid): void {
        $this->create_webhook($paymentaccountid);
        $webhook = $this->get_webhook($paymentaccountid);
        $events = $webhook->enabled_events;
        if (in_array('*', $events, true)) {
            return;
        }
        $required = ['invoice.paid', 'invoice.voided'];
        if (array_diff($required, $events)) {
            $this->stripe->webhookEndpoints->update($webhook->id, [
                'enabled_events' => array_values(array_unique(array_merge($events, $required))),
            ]);
        }
    }

    /**
     * Delete a webhook record in the database and it's associated Stripe endpoint.
     *
     * @param int $paymentaccountid
     * @return bool
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function delete_webhook(int $paymentaccountid): bool {
        if (!($record = $this->webhookrepository->find_by_paymentaccountid($paymentaccountid))) {
            return false;
        }

        $this->webhookrepository->delete($record);

        $this->stripe->webhookEndpoints->delete($record->webhookid);

        return true;
    }
}
