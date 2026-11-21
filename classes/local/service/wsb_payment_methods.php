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
 * Payment methods of a Checkout Session (WSB Merito).
 *
 * "Dynamic payment methods" (the WSB version's checkbox, on in production) = the methods enabled in the Stripe
 * Dashboard. In the upstream version that is a payment method configuration: the one selected on the payment account,
 * or the account's default. With the checkbox off, the session is limited to the methods selected on the account
 * (payment_method_types) – Stripe does not accept both parameters together. A configuration saved before the checkbox
 * existed counts as dynamic (the upstream behaviour).
 *
 * @package   paygw_stripe
 * @copyright 2026 WSB Merito
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_payment_methods {
    /**
     * Whether the session uses the methods configured in the Stripe Dashboard.
     *
     * @param object $config Gateway configuration
     * @return bool
     */
    public static function is_dynamic(object $config): bool {
        return !isset($config->usedynamicpaymentmethods) || !empty($config->usedynamicpaymentmethods);
    }

    /**
     * Checkout Session parameters choosing the payment methods.
     *
     * @param object $config Gateway configuration
     * @param payment_method_config_service $service Resolves the default payment method configuration (dynamic only)
     * @return array Either ['payment_method_configuration' => …] or ['payment_method_types' => […]]
     */
    public static function session_params(object $config, payment_method_config_service $service): array {
        if (!self::is_dynamic($config)) {
            $types = array_values(array_filter((array) ($config->paymentmethods ?? []), 'strlen'));
            return ['payment_method_types' => $types ?: ['card']];
        }
        $id = $config->paymentmethodconfiguration ?? null;
        if (empty($id)) {
            $id = $service->get_default_payment_method_config_id();
        }
        return ['payment_method_configuration' => $id];
    }
}
