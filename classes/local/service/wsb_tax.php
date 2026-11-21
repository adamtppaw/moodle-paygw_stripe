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

namespace paygw_stripe\local\service;

/**
 * Tax mode of a payment account (WSB Merito).
 *
 * One selector "taxmode": none, automatic (Stripe Tax, automatic_tax) or manual (a fixed Stripe Tax Rate txr_…,
 * e.g. the Polish "zw" exemption, which does not need Stripe Tax). Stripe forbids combining automatic_tax with manual
 * tax rates, hence one selector. Configurations saved before the selector existed only have the "enableautomatictax"
 * checkbox, which is still honoured.
 *
 * @package   paygw_stripe
 * @copyright 2026 WSB Merito
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_tax {
    /**
     * Whether Stripe Tax (automatic_tax) is on for this configuration.
     *
     * @param object $config Gateway configuration
     * @return bool
     */
    public static function is_automatic(object $config): bool {
        if (isset($config->taxmode) && $config->taxmode !== '') {
            return $config->taxmode === 'automatic';
        }
        return !empty($config->enableautomatictax);
    }

    /**
     * The manual Stripe Tax Rate ID to apply, or null (only in manual mode).
     *
     * @param object $config Gateway configuration
     * @return string|null
     */
    public static function manual_rate(object $config): ?string {
        if (($config->taxmode ?? '') !== 'manual') {
            return null;
        }
        $rate = trim((string) ($config->manualtaxrate ?? ''));
        return $rate !== '' ? $rate : null;
    }
}
