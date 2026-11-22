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
 * WSB Merito: return the plugin tables to the shape release 1.39 expects, without restoring the whole site database.
 *
 * Order on the server:
 *   1. php payment/gateway/stripe/cli/wsb_rollback_139.php            (dry run – shows what would change)
 *   2. php payment/gateway/stripe/cli/wsb_rollback_139.php --execute
 *   3. replace payment/gateway/stripe with release 1.39 and run php admin/cli/purge_caches.php
 *
 * Only what 1.39 depends on is undone: paygw_stripe_checkout_sessions is renamed back to paygw_stripe_intents and
 * the plugin version is set to 1.39. Every row stays. Columns and tables added by the update (checkoutsessionid,
 * delivered, billingmanaged, paygw_stripe_invoices) also stay: 1.39 ignores them, and a later update finds them and
 * leaves the data alone. Nothing is changed in Stripe.
 *
 * @package    paygw_stripe
 * @copyright  2026 WSB Merito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/ddllib.php');

[$options, $unrecognized] = cli_get_params(['execute' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognized) {
    echo "Return paygw_stripe tables to release 1.39 (version 2026110103).\n" .
        "  --execute   apply the changes (without it: dry run)\n";
    exit(0);
}

const PAYGW_STRIPE_VERSION_139 = 2026110103;

$dbman = $DB->get_manager();
$sessions = new xmldb_table('paygw_stripe_checkout_sessions');
$intents = new xmldb_table('paygw_stripe_intents');
$version = (int) get_config('paygw_stripe', 'version');

if ($dbman->table_exists($sessions) && $dbman->table_exists($intents)) {
    cli_error('Both paygw_stripe_checkout_sessions and paygw_stripe_intents exist – stopping, check the database first.');
}
$plan = [];
if ($dbman->table_exists($sessions)) {
    $plan[] = 'rename paygw_stripe_checkout_sessions to paygw_stripe_intents (' .
        $DB->count_records('paygw_stripe_checkout_sessions') . ' rows kept)';
}
if ($version !== PAYGW_STRIPE_VERSION_139) {
    $plan[] = "set plugin version $version -> " . PAYGW_STRIPE_VERSION_139;
}

$tables = ['paygw_stripe_products', 'paygw_stripe_customers', 'paygw_stripe_subscriptions', 'paygw_stripe_webhooks',
    'paygw_stripe_checkout_sessions', 'paygw_stripe_intents'];
$counts = [];
foreach ($tables as $t) {
    if ($dbman->table_exists(new xmldb_table($t))) {
        $counts[$t] = $DB->count_records($t);
        mtrace("rows: $t = {$counts[$t]}");
    }
}

if (!$plan) {
    mtrace('Nothing to do – the plugin tables already match 1.39.');
    exit(0);
}
foreach ($plan as $step) {
    mtrace(($options['execute'] ? 'DO: ' : 'WOULD: ') . $step);
}
if (!$options['execute']) {
    mtrace('Dry run. Add --execute to apply.');
    exit(0);
}

// DDL cannot run inside a transaction; the rename is a single statement, the version is set only after it.
if ($dbman->table_exists($sessions)) {
    $dbman->rename_table($sessions, 'paygw_stripe_intents');
}
set_config('version', PAYGW_STRIPE_VERSION_139, 'paygw_stripe');

// Row counts must be the same as before.
foreach ($counts as $t => $n) {
    $now = $t === 'paygw_stripe_checkout_sessions' ? 'paygw_stripe_intents' : $t;
    $after = $DB->count_records($now);
    if ($after !== $n) {
        cli_error("Row count changed in $now: $n -> $after");
    }
}
purge_all_caches();
mtrace('Done. Now replace payment/gateway/stripe with release 1.39 and run admin/cli/purge_caches.php.');
