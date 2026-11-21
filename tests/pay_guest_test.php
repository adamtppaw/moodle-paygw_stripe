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

namespace paygw_stripe;

defined('MOODLE_INTERNAL') || die();

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Regression test: a guest opening a purchase link is sent to the login page.
 *
 * With automatic guest login enabled, a visitor who is not logged in becomes the guest user. pay.php used to create a
 * Stripe customer with the guest's e-mail address (root@localhost), which Stripe rejects ("Invalid email address").
 *
 * @package paygw_stripe
 * @category test
 * @coversNothing
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pay_guest_test extends \advanced_testcase {
    /** @var ClientInterface|null Original SDK HTTP client. */
    private ?ClientInterface $originalhttpclient;
    /** @var array Original request globals. */
    private array $request;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->request = [$_GET, $_POST, $_SERVER];
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $this->originalhttpclient = $property->getValue();
    }

    protected function tearDown(): void {
        [$_GET, $_POST, $_SERVER] = $this->request;
        ApiRequestor::setHttpClient($this->originalhttpclient);
        parent::tearDown();
    }

    public function test_guest_is_sent_to_login_without_contacting_stripe(): void {
        global $CFG, $DB, $SESSION, $USER, $PAGE, $OUTPUT;

        set_config('autologinguests', 1);
        $this->setGuestUser();
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($http);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['component' => 'enrol_fee', 'paymentarea' => 'fee', 'itemid' => 1];
        $cwd = getcwd();
        chdir($CFG->dirroot . '/payment/gateway/stripe');
        try {
            require($CFG->dirroot . '/payment/gateway/stripe/pay.php');
            $this->fail('Expected a redirect to the login page.');
        } catch (\moodle_exception $e) {
            // CLI execution raises this exception instead of issuing the redirect.
            $this->assertSame('redirecterrordetected', $e->errorcode);
        } finally {
            chdir($cwd);
        }
        $this->assertStringContainsString('/payment/gateway/stripe/pay.php', $SESSION->wantsurl);
        $this->assertSame(0, $DB->count_records('paygw_stripe_customers'));
    }
}
