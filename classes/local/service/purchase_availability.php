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
 * Checks whether an enrolment can be bought right now.
 *
 * @package    paygw_stripe
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe\local\service;

use moodle_url;

/**
 * Stops a payment for an enrolment the user could not get after paying.
 *
 * pay.php is reachable directly (bookmarks, links in e-mails, an old browser tab), so a course that was hidden,
 * an enrolment method that was disabled or whose enrolment period ended, or a user who is already enrolled,
 * would otherwise lead to a successful payment without access.
 */
final class purchase_availability {
    /**
     * Redirect away from pay.php when the purchase is not available; returns when it is.
     *
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $userid
     */
    public static function require_available(string $component, string $paymentarea, int $itemid, int $userid): void {
        global $DB;
        if ($component !== 'enrol_fee' || $paymentarea !== 'fee') {
            return;
        }
        if (!$instance = $DB->get_record('enrol', ['id' => $itemid, 'enrol' => 'fee'])) {
            return;
        }
        if (!$reason = self::get_unavailable_reason($instance, $userid)) {
            return;
        }
        $url = $reason === 'purchaseunavailable_coursehidden'
            ? new moodle_url('/')
            : new moodle_url('/course/view.php', ['id' => $instance->courseid]);
        redirect($url, get_string($reason, 'paygw_stripe'), null, \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * Reason why an enrolment instance cannot be bought now, or null when it can.
     *
     * @param \stdClass $instance mdl_enrol record
     * @param int $userid Buyer
     * @return string|null Language string identifier (paygw_stripe).
     */
    public static function get_unavailable_reason(\stdClass $instance, int $userid): ?string {
        global $DB;
        $course = $DB->get_record('course', ['id' => $instance->courseid], 'id, visible');
        if (!$course) {
            return 'purchaseunavailable_coursehidden';
        }
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if ($ue && (int) $ue->status === ENROL_USER_SUSPENDED) {
            // A suspended enrolment stays suspended after payment, so the user would pay without getting access.
            return 'purchaseunavailable_enrolsuspended';
        }
        $now = time();
        if ($ue && (empty($ue->timestart) || $ue->timestart <= $now) && (empty($ue->timeend) || $ue->timeend >= $now)) {
            return 'purchaseunavailable_alreadyenrolled';
        }
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', \context_course::instance($course->id))) {
            return 'purchaseunavailable_coursehidden';
        }
        if (!enrol_is_enabled($instance->enrol) || (int) $instance->status !== ENROL_INSTANCE_ENABLED) {
            return 'purchaseunavailable_enroldisabled';
        }
        if (!empty($instance->enrolstartdate) && $instance->enrolstartdate > $now) {
            return 'purchaseunavailable_notstarted';
        }
        if (!empty($instance->enrolenddate) && $instance->enrolenddate < $now) {
            return 'purchaseunavailable_ended';
        }
        return null;
    }
}
