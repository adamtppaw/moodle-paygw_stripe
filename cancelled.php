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
 * Redirects cancelled payments back to the course page or site index
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

require_login();

$component = optional_param('component', null, PARAM_ALPHANUMEXT);
$paymentarea = optional_param('paymentarea', null, PARAM_ALPHANUMEXT);
$itemid = optional_param('itemid', null, PARAM_INT);

$url = new moodle_url('/');

// Return to the course for any enrolment plugin (enrol_fee, enrol_feestripe, ...),
// or to the site home page when the user cannot see the course (hidden subscription course).
if ($component && $paymentarea && $itemid) {
    $url = \paygw_stripe\stripe_helper::get_return_url($component, $paymentarea, $itemid, $USER->id, false);
}

redirect($url, get_string('paymentcancelled', 'paygw_stripe'));
