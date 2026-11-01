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

namespace paygw_stripe\task;

use core_payment\helper;
use paygw_stripe\stripe_helper;

/**
 * Daily check of running subscriptions against Stripe (safety net for lost webhooks).
 *
 * Every subscription that Moodle still considers running is read from Stripe. A subscription
 * that has ended there is handled like customer.subscription.deleted (status and unenrolment);
 * other status changes are only stored. Subscriptions missing in Stripe and API errors are
 * reported in the task log and never cause an unenrolment. When any subscription could not be
 * checked, the task ends with an error so that it is marked as failed in the task log.
 *
 * @package    paygw_stripe
 * @copyright  2026 WSB Merito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_subscriptions extends \core\task\scheduled_task {

    /** Statuses after which a subscription can no longer change in Stripe. */
    const FINAL_STATUSES = ['canceled', 'incomplete_expired'];

    /**
     * Task name shown in the scheduled tasks list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_reconcilesubscriptions', 'paygw_stripe');
    }

    /**
     * Run the check.
     *
     * @return void
     * @throws \moodle_exception When some subscriptions could not be checked.
     */
    public function execute() {
        global $DB;

        [$notin, $params] = $DB->get_in_or_equal(self::FINAL_STATUSES, SQL_PARAMS_NAMED, 'st', false);
        $records = $DB->get_records_select('paygw_stripe_subscriptions', "status IS NULL OR status $notin", $params, 'id');
        mtrace('paygw_stripe: ' . count($records) . ' running subscription(s) to check.');

        $counts = ['unchanged' => 0, 'updated' => 0, 'ended' => 0, 'missing' => 0, 'skipped' => 0, 'error' => 0];
        $helpers = [];
        foreach ($records as $moodlesub) {
            $label = "subscription {$moodlesub->subscriptionid} (record {$moodlesub->id}, user {$moodlesub->userid})";
            $item = stripe_helper::get_subscription_item($moodlesub);
            if (!$item) {
                mtrace("  SKIPPED $label: no payment item (plan) for this subscription.");
                $counts['skipped']++;
                continue;
            }
            try {
                $config = (object) helper::get_gateway_configuration($item->component, $item->paymentarea, $item->itemid,
                        'stripe');
            } catch (\moodle_exception $e) {
                mtrace("  SKIPPED $label: no gateway configuration for {$item->component} {$item->itemid} (" .
                        $e->getMessage() . ').');
                $counts['skipped']++;
                continue;
            }
            $key = $config->secretkey ?? '';
            if (!isset($helpers[$key])) {
                $helpers[$key] = new stripe_helper($config->apikey ?? '', $key);
            }
            try {
                [$outcome, $status] = $helpers[$key]->reconcile_subscription($moodlesub);
            } catch (\Throwable $e) {
                mtrace("  ERROR $label: " . $e->getMessage());
                $counts['error']++;
                continue;
            }
            $counts[$outcome]++;
            $stored = $moodlesub->status ?? 'none';
            switch ($outcome) {
                case 'ended':
                    mtrace("  ENDED $label: canceled in Stripe, stored status was $stored - user unenrolled from the plan.");
                    break;
                case 'updated':
                    mtrace("  UPDATED $label: status $stored -> $status.");
                    if (in_array($status, ['unpaid', 'paused'])) {
                        mtrace("  WARNING $label: status $status keeps access without payment - Stripe should cancel " .
                                'subscriptions after all payment retries fail (Billing settings).');
                    }
                    break;
                case 'missing':
                    mtrace("  MISSING $label: not found in Stripe (wrong API keys or broken record) - nothing changed.");
                    break;
            }
        }
        mtrace('paygw_stripe: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($counts), $counts)) . '.');
        if ($counts['error'] || $counts['missing']) {
            throw new \moodle_exception('task_reconcilesubscriptions_incomplete', 'paygw_stripe', '',
                    $counts['error'] + $counts['missing']);
        }
    }
}
