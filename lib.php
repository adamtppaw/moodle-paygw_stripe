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

/**
 * Lib functions.
 *
 * @package    paygw_stripe
 * @author     Alex Morris <alex@navra.nz>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_user\output\myprofile\category;
use core_user\output\myprofile\node;
use paygw_stripe\stripe_helper;

/**
 * User profile page callback.
 *
 * Adds a section for Stripe subscriptions and a link to the Stripe customer portal.
 *
 * @param \core_user\output\myprofile\tree $tree My profile tree where the setting will be added.
 * @param stdClass $user The user object.
 * @param bool $iscurrentuser Is this the current user viewing?
 * @return void
 */
function paygw_stripe_myprofile_navigation(\core_user\output\myprofile\tree $tree, stdClass $user, bool $iscurrentuser): void {
    global $USER, $DB;

    if (!$iscurrentuser || !isloggedin() || isguestuser()) {
        return;
    }

    // Add category if it doesn't exist.
    $tree->add_category(new category('paygw_stripe', get_string('profilecat', 'paygw_stripe'), 'loginactivity'));

    // Link to subscription management.
    $tree->add_node(new node(
            'paygw_stripe',
            'cancelsubscriptions',
            get_string('cancelsubscriptions', 'paygw_stripe'),
            null,
            new moodle_url('/payment/gateway/stripe/subscriptions.php')
    ));


    // Link to customer portal.
    if ($user_stripe_payments = (array) $DB->get_records(
            'payments',['userid'=>$USER->id, 'gateway'=>'stripe'],'id DESC', '*',0,1)) {
        foreach ($user_stripe_payments as $user_stripe_payment) {
            $config = (object) \core_payment\helper::get_gateway_configuration(
                    $user_stripe_payment->component,
                    $user_stripe_payment->paymentarea,
                    $user_stripe_payment->itemid,
                    $user_stripe_payment->gateway
            );
        };
        if ($config) {
            $helper = new \paygw_stripe\stripe_helper($config->apikey, $config->secretkey);
            $portalurl = $helper->get_customer_portal_url((int) $USER->id);
        }
    }
    if (!empty($portalurl)) {
        $tree->add_node(new node(
                'paygw_stripe',
                'stripeinvoices',
                get_string('stripeinvoices', 'paygw_stripe'),
                null,
                new moodle_url($portalurl)
        ));
    }
}
