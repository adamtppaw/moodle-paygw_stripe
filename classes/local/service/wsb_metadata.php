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

use moodle_url;

/**
 * Product names and moodle_* metadata for Stripe objects (WSB Merito additions).
 *
 * Every product, customer and payment gets "moodle_*" metadata describing the course and the user,
 * so a payment can be identified in the Stripe Dashboard without looking it up in Moodle. Product
 * names are built on the server ("Rejestracja na <course>") for any enrol_* component, not only
 * enrol_fee, and do not depend on the buyer's language.
 *
 * @package   paygw_stripe
 * @copyright 2026 WSB Merito
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_metadata {
    /** Stripe limit of a metadata value. */
    private const MAX_VALUE = 500;

    /**
     * The enrolment instance behind a payment item of any enrol_* component (enrol_fee, enrol_feestripe…).
     *
     * @param string $component
     * @param mixed $itemid
     * @return \stdClass|null
     */
    public static function resolve_enrol_instance(string $component, $itemid): ?\stdClass {
        global $DB;
        if (strpos($component, 'enrol_') !== 0 || empty($itemid)) {
            return null;
        }
        $enrol = $DB->get_record('enrol', ['id' => $itemid]);
        if (!$enrol || $enrol->enrol !== substr($component, strlen('enrol_'))) {
            return null;
        }
        return $enrol;
    }

    /**
     * Name of the Stripe product: "Rejestracja na <course>", or "Registration for <course>" when the course
     * forces a language other than Polish; formatted in that fixed language, never in the buyer's.
     * Items that are not courses fall back to the given description.
     *
     * @param string $component
     * @param mixed $itemid
     * @param string $fallback
     * @return string
     */
    public static function product_name(string $component, $itemid, string $fallback): string {
        global $CFG, $DB;
        if (($enrol = self::resolve_enrol_instance($component, $itemid))
                && ($course = $DB->get_record('course', ['id' => $enrol->courseid]))) {
            $courselang = trim((string) $course->lang);
            $polish = $courselang === '' || $courselang === 'pl' || strpos($courselang, 'pl_') === 0;
            $prefix = $polish ? 'Rejestracja na ' : 'Registration for ';
            $previous = force_current_language($courselang !== '' ? $courselang : ($CFG->lang ?? ''));
            try {
                $fullname = self::plain($course->fullname, \context_course::instance($course->id));
            } finally {
                force_current_language($previous);
            }
            return mb_substr($prefix . trim($fullname), 0, 250);
        }
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $fallback)), 0, 250);
    }

    /**
     * moodle_* metadata of the item a product represents.
     *
     * @param string $component
     * @param string $paymentarea
     * @param mixed $itemid
     * @return array<string, string>
     */
    public static function product(string $component, string $paymentarea, $itemid): array {
        global $CFG, $DB;
        $metadata = [
            'moodle_component' => $component,
            'moodle_paymentarea' => $paymentarea,
            'moodle_site' => $CFG->wwwroot,
        ];
        $resolved = false;
        if ($enrol = self::resolve_enrol_instance($component, $itemid)) {
            $resolved = true;
            $metadata['moodle_product_type'] = 'course';
            $metadata['moodle_enrol_id'] = (string) $enrol->id;
            if ($plugin = enrol_get_plugin($enrol->enrol)) {
                $name = trim((string) $plugin->get_instance_name($enrol));
                if ($name !== '') {
                    $metadata['moodle_enrol_name'] = $name;
                }
            }
            if ($course = $DB->get_record('course', ['id' => $enrol->courseid])) {
                $context = \context_course::instance($course->id);
                $metadata['moodle_course_id'] = (string) $course->id;
                if (trim((string) $course->idnumber) !== '') {
                    $metadata['moodle_course_idnumber'] = (string) $course->idnumber;
                }
                if (trim((string) $course->fullname) !== '') {
                    $metadata['moodle_course_fullname'] = self::plain($course->fullname, $context);
                }
                if (trim((string) $course->shortname) !== '') {
                    $metadata['moodle_course_shortname'] = self::plain($course->shortname, $context);
                }
                $metadata['moodle_course_url'] = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            }
        }
        if (!$resolved) {
            $metadata['moodle_itemid'] = (string) $itemid;
        }
        return self::clamp($metadata);
    }

    /**
     * moodle_* metadata of the Moodle user a customer represents (no billing details).
     *
     * @param \stdClass $user
     * @return array<string, string>
     */
    public static function customer($user): array {
        global $CFG;
        $metadata = [
            'moodle_user_id' => (string) $user->id,
            'moodle_username' => (string) ($user->username ?? ''),
            'moodle_site' => $CFG->wwwroot,
        ];
        $fullname = trim(fullname($user));
        if ($fullname !== '') {
            $metadata['moodle_user_fullname'] = $fullname;
        }
        foreach (['idnumber' => 'moodle_user_idnumber', 'institution' => 'moodle_institution',
                'department' => 'moodle_department'] as $field => $key) {
            if (isset($user->$field) && trim((string) $user->$field) !== '') {
                $metadata[$key] = (string) $user->$field;
            }
        }
        return self::clamp($metadata);
    }

    /**
     * Metadata of a payment (Checkout Session, payment intent, subscription): the historical unprefixed
     * keys read by webhook.php, plus the product and customer moodle_* keys.
     *
     * @param \stdClass $user
     * @param string $component
     * @param string $paymentarea
     * @param mixed $itemid
     * @return array<string, string>
     */
    public static function payment($user, string $component, string $paymentarea, $itemid): array {
        return array_merge([
            'userid' => $user->id,
            'username' => $user->username,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
        ], self::product($component, $paymentarea, $itemid), self::customer($user));
    }

    /**
     * Whether existing metadata misses any desired key or holds another value (extra keys are ignored).
     *
     * @param mixed $existing StripeObject, array or null
     * @param array $desired
     * @return bool
     */
    public static function needs_update($existing, array $desired): bool {
        $current = $existing === null ? [] : (is_array($existing) ? $existing : $existing->toArray());
        foreach ($desired as $key => $value) {
            if (!array_key_exists($key, $current) || (string) $current[$key] !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Product metadata update for an existing product: desired keys, plus removal of a stale moodle_itemid
     * once the course resolves (Stripe merges metadata; an empty value deletes a key).
     *
     * @param mixed $existing
     * @param string $component
     * @param string $paymentarea
     * @param mixed $itemid
     * @return array|null Metadata to send, or null when nothing changes.
     */
    public static function product_update($existing, string $component, string $paymentarea, $itemid): ?array {
        $desired = self::product($component, $paymentarea, $itemid);
        $current = $existing === null ? [] : (is_array($existing) ? $existing : $existing->toArray());
        if (!isset($desired['moodle_itemid']) && array_key_exists('moodle_itemid', $current)) {
            $desired['moodle_itemid'] = '';
        }
        return self::needs_update($existing, $desired) ? $desired : null;
    }

    /**
     * Course name as plain text: filters applied (e.g. multilang), no HTML entities ("&" stays "&").
     *
     * @param string $text
     * @param \context $context
     * @return string
     */
    private static function plain(string $text, \context $context): string {
        return trim(html_to_text(format_string($text, true, ['context' => $context]), 0, false));
    }

    /**
     * Clamp every value to Stripe's limit.
     *
     * @param array $metadata
     * @return array<string, string>
     */
    private static function clamp(array $metadata): array {
        foreach ($metadata as $key => $value) {
            $metadata[$key] = mb_substr((string) $value, 0, self::MAX_VALUE);
        }
        return $metadata;
    }
}
