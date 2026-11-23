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
 * Tests for webhook service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\tests\fixtures\stripe_testcase;
use paygw_stripe\stripe_helper;
use Stripe\Service\WebhookEndpointService;
use Stripe\WebhookEndpoint;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Tests for webhook_service.
 *
 * @covers \paygw_stripe\local\service\webhook_service
 */
final class webhook_service_test extends stripe_testcase {
    /**
     * Tests webhook create, fetch and delete flow.
     */
    public function test_webhook_lifecycle_methods(): void {
        global $CFG;

        $endpoint = WebhookEndpoint::constructFrom(['id' => 'we_test', 'secret' => 'whsec_fake']);
        $webhooks = $this->mock_stripe_service('webhookEndpoints', WebhookEndpointService::class);
        $webhooks->expects($this->once())->method('create')->with([
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
        ])->willReturn($endpoint);
        $webhooks->expects($this->exactly(2))->method('retrieve')->with('we_test')->willReturn($endpoint);
        $webhooks->expects($this->once())->method('delete')->with('we_test')
            ->willReturn(WebhookEndpoint::constructFrom(['id' => 'we_test', 'deleted' => true]));
        $service = new webhook_service($this->client);

        $this->assertTrue($service->create_webhook(444));
        $this->assertFalse($service->create_webhook(444));

        $webhook = $service->get_webhook(444);
        $this->assertNotNull($webhook);
        $this->assertSame('whsec_fake', $webhook->secret);

        $this->assertTrue($service->delete_webhook(444));
        $this->assertFalse($service->delete_webhook(444));
        $this->assertNull($service->get_webhook(444));
    }

    /**
     * An endpoint deleted in Stripe is forgotten, so a new one is created instead of every purchase failing.
     */
    public function test_endpoint_deleted_in_stripe_is_recreated(): void {
        global $DB;

        $DB->insert_record('paygw_stripe_webhooks', (object)[
            'paymentaccountid' => 555,
            'webhookid' => 'we_deleted',
            'secret' => 'whsec_old',
        ]);
        $webhooks = $this->mock_stripe_service('webhookEndpoints', WebhookEndpointService::class);
        $webhooks->method('retrieve')->with('we_deleted')->willThrowException(
            \Stripe\Exception\InvalidRequestException::factory('No such webhook endpoint', 404, null, null, null,
                'resource_missing')
        );
        $webhooks->expects($this->once())->method('create')
            ->willReturn(WebhookEndpoint::constructFrom(['id' => 'we_new', 'secret' => 'whsec_new']));
        $service = new webhook_service($this->client);

        $this->assertNull($service->get_webhook(555));
        $this->assertFalse($DB->record_exists('paygw_stripe_webhooks', ['webhookid' => 'we_deleted']));
        $this->assertTrue($service->create_webhook(555));
        $this->assertSame('whsec_new', $DB->get_field('paygw_stripe_webhooks', 'secret', ['paymentaccountid' => 555]));
    }
    /**
     * New API keys of the same Stripe account (a rotated key) keep the existing endpoint and its signing secret.
     */
    public function test_new_keys_of_same_account_keep_endpoint(): void {
        global $DB;

        $DB->insert_record('paygw_stripe_webhooks', (object)[
            'paymentaccountid' => 556,
            'webhookid' => 'we_kept',
            'secret' => 'whsec_kept',
        ]);
        $webhooks = $this->mock_stripe_service('webhookEndpoints', WebhookEndpointService::class);
        $webhooks->expects($this->once())->method('retrieve')->with('we_kept')
            ->willReturn(WebhookEndpoint::constructFrom(['id' => 'we_kept']));
        $webhooks->expects($this->never())->method('create');
        $webhooks->expects($this->never())->method('delete');
        $service = new webhook_service($this->client);

        $this->assertFalse($service->update_webhook_for_new_keys(556));
        $records = $DB->get_records('paygw_stripe_webhooks', ['paymentaccountid' => 556]);
        $this->assertCount(1, $records);
        $this->assertSame('whsec_kept', reset($records)->secret);
    }

    /**
     * New API keys of another Stripe account get their own endpoint; the previous one is not deleted, because the old
     * keys may still serve another site.
     */
    public function test_new_keys_of_another_account_create_endpoint_without_deleting_old(): void {
        global $DB;

        $DB->insert_record('paygw_stripe_webhooks', (object)[
            'paymentaccountid' => 557,
            'webhookid' => 'we_other_account',
            'secret' => 'whsec_other',
        ]);
        $webhooks = $this->mock_stripe_service('webhookEndpoints', WebhookEndpointService::class);
        $webhooks->method('retrieve')->with('we_other_account')->willThrowException(
            \Stripe\Exception\InvalidRequestException::factory('No such webhook endpoint', 404, null, null, null,
                'resource_missing')
        );
        $webhooks->expects($this->once())->method('create')
            ->willReturn(WebhookEndpoint::constructFrom(['id' => 'we_new_account', 'secret' => 'whsec_new_account']));
        $webhooks->expects($this->never())->method('delete');
        $service = new webhook_service($this->client);

        $this->assertTrue($service->update_webhook_for_new_keys(557));
        $records = $DB->get_records('paygw_stripe_webhooks', ['paymentaccountid' => 557]);
        $this->assertCount(1, $records);
        $this->assertSame('we_new_account', reset($records)->webhookid);
        $this->assertSame('whsec_new_account', reset($records)->secret);
    }
}
