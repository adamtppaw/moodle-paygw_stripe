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
 * Tax mode of a payment account (WSB Merito).
 *
 * @package paygw_stripe
 * @covers \paygw_stripe\local\service\wsb_tax
 * @copyright 2026 WSB Merito
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_tax_test extends \basic_testcase {
    /**
     * Configurations and the expected automatic tax flag and manual rate.
     *
     * @return array
     */
    public static function config_provider(): array {
        return [
            'manual zw' => [['taxmode' => 'manual', 'manualtaxrate' => ' txr_zw ', 'enableautomatictax' => 1], false, 'txr_zw'],
            'manual without rate' => [['taxmode' => 'manual', 'manualtaxrate' => ''], false, null],
            'automatic' => [['taxmode' => 'automatic', 'manualtaxrate' => 'txr_zw'], true, null],
            'none' => [['taxmode' => 'none', 'enableautomatictax' => 1], false, null],
            'legacy checkbox on' => [['enableautomatictax' => 1], true, null],
            'legacy checkbox off' => [['enableautomatictax' => 0], false, null],
        ];
    }

    /**
     * Tax mode and manual tax rate are read from the account configuration.
     *
     * @dataProvider config_provider
     * @param array $config
     * @param bool $automatic
     * @param string|null $rate
     */
    public function test_tax_mode(array $config, bool $automatic, ?string $rate): void {
        $this->assertSame($automatic, wsb_tax::is_automatic((object) $config));
        $this->assertSame($rate, wsb_tax::manual_rate((object) $config));
    }
}
