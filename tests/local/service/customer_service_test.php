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
 * Tests for customer service logic.
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
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\CustomerService;
use Stripe\Service\BillingPortal\BillingPortalServiceFactory;
use Stripe\Service\BillingPortal\SessionService as PortalSessionService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Tests for customer_service.
 *
 * @covers \paygw_stripe\local\service\customer_service
 */
final class customer_service_test extends stripe_testcase {
    /**
     * Tests get_customer deletes stale DB record if Stripe retrieval fails.
     */
    public function test_get_customer_deletes_stale_record_on_api_error(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('paygw_stripe_customers', (object)[
            'userid' => $user->id,
            'customerid' => 'cus_missing',
        ]);

        $customers = $this->mock_stripe_service('customers', CustomerService::class);
        $customers->expects($this->once())->method('retrieve')->with('cus_missing')->willThrowException(
            InvalidRequestException::factory('Missing customer', 404, null, null, null, 'resource_missing')
        );
        $service = new customer_service($this->client);

        $this->assertNull($service->get_customer((int)$user->id));
        $this->assertFalse($DB->record_exists('paygw_stripe_customers', ['userid' => $user->id]));
    }

    /**
     * Provide the customer's name in Stripe and whether the Moodle full name is sent.
     *
     * @return array
     */
    public static function customer_name_provider(): array {
        return [
            'company name from Checkout is kept' => ['ACME Sp. z o.o.', false],
            'empty name is filled' => ['', true],
        ];
    }

    /**
     * WSB Merito: a later purchase does not overwrite the name entered in Checkout (a company name on invoices).
     *
     * @dataProvider customer_name_provider
     * @param string $stripename Name stored in Stripe
     * @param bool $sendsname Whether update_customer_details() sends the Moodle full name
     */
    public function test_update_customer_details_keeps_checkout_name(string $stripename, bool $sendsname): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jan', 'lastname' => 'Kowalski',
            'email' => 'jan@example.com']);
        $customer = \Stripe\Customer::constructFrom(['id' => 'cus_wsb', 'name' => $stripename, 'metadata' => []]);
        $sent = null;
        $customers = $this->mock_stripe_service('customers', CustomerService::class);
        $customers->expects($this->once())->method('update')->with('cus_wsb')
            ->willReturnCallback(function (string $id, array $details) use (&$sent, $customer) {
                $sent = $details;
                return $customer;
            });

        (new customer_service($this->client))->update_customer_details($customer, $user);

        $this->assertSame('jan@example.com', $sent['email']);
        if ($sendsname) {
            $this->assertSame('Jan Kowalski', $sent['name']);
        } else {
            $this->assertArrayNotHasKey('name', $sent);
        }
    }

    /**
     * WSB Merito: the profile link opens the Stripe customer portal for a known customer.
     */
    public function test_get_customer_portal_url_for_known_customer(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('paygw_stripe_customers', (object)[
            'userid' => $user->id,
            'customerid' => 'cus_portal',
        ]);
        $portal = $this->mock_stripe_service('billingPortal', BillingPortalServiceFactory::class);
        $sessions = $this->createMock(PortalSessionService::class);
        $portal->method('__get')->with('sessions')->willReturn($sessions);
        $payload = [];
        $sessions->expects($this->once())->method('create')
            ->willReturnCallback(function (array $params) use (&$payload): \Stripe\BillingPortal\Session {
                $payload = $params;
                return \Stripe\BillingPortal\Session::constructFrom(['id' => 'bps_1', 'url' => 'https://example.test/portal']);
            });
        $service = new customer_service($this->client);

        $this->assertSame('https://example.test/portal', $service->get_customer_portal_url((int)$user->id));
        $this->assertSame('cus_portal', $payload['customer']);
        $this->assertStringContainsString('/user/profile.php', $payload['return_url']);
    }

    /**
     * WSB Merito: no portal link for a user who never bought anything.
     */
    public function test_get_customer_portal_url_without_customer(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->mock_stripe_service('billingPortal', BillingPortalServiceFactory::class)
            ->expects($this->never())->method('__get');
        $service = new customer_service($this->client);

        $this->assertNull($service->get_customer_portal_url((int)$user->id));
    }
}
