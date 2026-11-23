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

namespace paygw_stripe\local\service;

/**
 * Payment methods of a Checkout Session (WSB Merito).
 *
 * @package paygw_stripe
 * @covers \paygw_stripe\local\service\wsb_payment_methods
 * @copyright 2026 WSB Merito
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_payment_methods_test extends \basic_testcase {
    /**
     * Configurations, the default configuration lookups expected and the session parameters.
     *
     * @return array
     */
    public static function config_provider(): array {
        return [
            'dynamic, default configuration' => [['usedynamicpaymentmethods' => '1', 'paymentmethodconfiguration' => ''], 1,
                ['payment_method_configuration' => 'pmc_default']],
            'dynamic, selected configuration' => [['usedynamicpaymentmethods' => 1, 'paymentmethodconfiguration' => 'pmc_x'], 0,
                ['payment_method_configuration' => 'pmc_x']],
            'saved before the checkbox existed' => [[], 1, ['payment_method_configuration' => 'pmc_default']],
            'manual methods' => [['usedynamicpaymentmethods' => '0', 'paymentmethods' => ['card', 'p24'],
                'paymentmethodconfiguration' => 'pmc_x'], 0, ['payment_method_types' => ['card', 'p24']]],
            'manual, nothing selected' => [['usedynamicpaymentmethods' => 0, 'paymentmethods' => []], 0,
                ['payment_method_types' => ['card']]],
        ];
    }

    /**
     * Checkout session parameters follow the payment method settings of the account.
     *
     * @dataProvider config_provider
     * @param array $config
     * @param int $lookups Expected calls to get_default_payment_method_config_id()
     * @param array $expected
     */
    public function test_session_params(array $config, int $lookups, array $expected): void {
        $service = $this->createMock(payment_method_config_service::class);
        $service->expects($this->exactly($lookups))->method('get_default_payment_method_config_id')->willReturn('pmc_default');
        $this->assertSame($expected, wsb_payment_methods::session_params((object) $config, $service));
    }
}
