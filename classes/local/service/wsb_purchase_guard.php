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

use core_payment\helper;
use moodle_url;
use paygw_stripe\stripe_helper;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Checks before a purchase and return URLs (WSB Merito additions).
 *
 * pay.php can be opened from a direct link, which bypasses the checks Moodle does before showing the
 * "Buy" button. A purchase is refused when the course is hidden (unless sold by a subscription account –
 * subscription plans are hidden "subscription courses"), the enrolment method is disabled or outside its
 * dates, the user already has access through it or a suspended enrolment in it, or – for subscriptions –
 * the user already has a running subscription.
 *
 * @package   paygw_stripe
 * @copyright 2026 WSB Merito
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_purchase_guard {
    /**
     * Redirect away from pay.php when the purchase is not available; returns when it is.
     *
     * @param object $config Gateway configuration of the payment account.
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $userid
     */
    public static function require_available(object $config, string $component, string $paymentarea, int $itemid,
            int $userid): void {
        $issubscription = ($config->type ?? '') === 'subscription';
        $subscriptionsurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
        if ($issubscription && self::get_running_subscription($userid)) {
            redirect($subscriptionsurl, get_string('purchaseunavailable_alreadysubscribed', 'paygw_stripe'), null,
                \core\output\notification::NOTIFY_WARNING);
        }
        if (!$instance = wsb_metadata::resolve_enrol_instance($component, $itemid)) {
            return;
        }
        if (!$reason = self::get_purchase_unavailable_reason($instance, $issubscription, $userid)) {
            return;
        }
        if ($reason === 'purchaseunavailable_coursehidden') {
            $url = new moodle_url('/');
        } else if ($reason === 'purchaseunavailable_alreadyenrolled' && $issubscription) {
            $url = $subscriptionsurl;
        } else if ($reason === 'purchaseunavailable_alreadyenrolled') {
            $url = self::get_return_url($component, $paymentarea, $itemid, $userid);
        } else {
            $url = self::get_return_url($component, $paymentarea, $itemid, $userid, false);
        }
        redirect($url, get_string($reason, 'paygw_stripe'), null, \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * Reason why an enrolment instance cannot be bought now, or null when it can.
     *
     * @param \stdClass $instance mdl_enrol record
     * @param bool $subscription Whether the payment account is in subscription mode.
     * @param int|null $userid Buyer; null skips the per-user checks.
     * @return string|null Language string identifier (paygw_stripe).
     */
    public static function get_purchase_unavailable_reason(\stdClass $instance, bool $subscription = false,
            ?int $userid = null): ?string {
        global $DB;
        $course = $DB->get_record('course', ['id' => $instance->courseid], 'id, visible');
        if (!$course) {
            return 'purchaseunavailable_coursehidden';
        }
        if ($userid && self::is_enrolled_via_instance($instance, $userid)) {
            return 'purchaseunavailable_alreadyenrolled';
        }
        // A suspended enrolment stays suspended after payment, so the user would pay without getting access.
        if ($userid && $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid,
                'status' => ENROL_USER_SUSPENDED])) {
            return 'purchaseunavailable_enrolsuspended';
        }
        $context = \context_course::instance($course->id);
        if (!$course->visible && !$subscription && !has_capability('moodle/course:viewhiddencourses', $context)) {
            return 'purchaseunavailable_coursehidden';
        }
        if (!enrol_is_enabled($instance->enrol) || (int) $instance->status !== ENROL_INSTANCE_ENABLED) {
            return 'purchaseunavailable_enroldisabled';
        }
        $now = time();
        if (!empty($instance->enrolstartdate) && $instance->enrolstartdate > $now) {
            return 'purchaseunavailable_notstarted';
        }
        if (!empty($instance->enrolenddate) && $instance->enrolenddate < $now) {
            return 'purchaseunavailable_ended';
        }
        return null;
    }

    /**
     * Whether the user has active access through this very instance (not suspended, within dates).
     *
     * @param \stdClass $instance
     * @param int $userid
     * @return bool
     */
    public static function is_enrolled_via_instance(\stdClass $instance, int $userid): bool {
        global $DB;
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if (!$ue || (int) $ue->status !== ENROL_USER_ACTIVE) {
            return false;
        }
        $now = time();
        return !((!empty($ue->timestart) && $ue->timestart > $now) || (!empty($ue->timeend) && $ue->timeend < $now));
    }

    /**
     * The payment item of a stored subscription: its own columns, or the product mapping for older rows.
     *
     * @param \stdClass $moodlesub paygw_stripe_subscriptions record
     * @return \stdClass|null {component, paymentarea, itemid}
     */
    public static function get_subscription_item(\stdClass $moodlesub): ?\stdClass {
        global $DB;
        if (!empty($moodlesub->component) && !empty($moodlesub->paymentarea) && !empty($moodlesub->itemid)) {
            return (object) ['component' => $moodlesub->component, 'paymentarea' => $moodlesub->paymentarea,
                'itemid' => (int) $moodlesub->itemid];
        }
        $products = $DB->get_records('paygw_stripe_products', ['productid' => $moodlesub->productid], 'id DESC',
            'id, component, paymentarea, itemid', 0, 1);
        $product = reset($products);
        return $product ? (object) ['component' => $product->component, 'paymentarea' => $product->paymentarea,
            'itemid' => (int) $product->itemid] : null;
    }

    /**
     * A running subscription of the user (it blocks buying another one), or null.
     *
     * Running = any status other than canceled, incomplete and incomplete_expired. Each candidate is checked
     * in Stripe first; one that has ended there is synchronised and does not block. When Stripe cannot be
     * reached, the stored status is trusted.
     *
     * @param int $userid
     * @return \stdClass|null paygw_stripe_subscriptions record
     */
    public static function get_running_subscription(int $userid): ?\stdClass {
        global $DB;
        [$notin, $params] = $DB->get_in_or_equal(['canceled', 'incomplete', 'incomplete_expired'], SQL_PARAMS_NAMED,
            'st', false);
        $params['userid'] = $userid;
        $candidates = $DB->get_records_select('paygw_stripe_subscriptions',
            "userid = :userid AND (status IS NULL OR status $notin)", $params, 'id DESC');
        foreach ($candidates as $moodlesub) {
            $item = self::get_subscription_item($moodlesub);
            if (!$item) {
                return $moodlesub;
            }
            try {
                $config = (object) helper::get_gateway_configuration($item->component, $item->paymentarea, $item->itemid,
                    'stripe');
                $stripe = new StripeClient(['api_key' => $config->secretkey, 'stripe_version' => stripe_helper::$apiversion]);
                try {
                    $subscription = $stripe->subscriptions->retrieve($moodlesub->subscriptionid);
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    $subscription = null;      // Deleted in Stripe.
                }
                if ($subscription && !in_array($subscription->status, ['canceled', 'incomplete_expired'])) {
                    if ($subscription->status !== $moodlesub->status) {
                        $DB->set_field('paygw_stripe_subscriptions', 'status', $subscription->status, ['id' => $moodlesub->id]);
                    }
                    if ($subscription->status !== 'incomplete') {
                        return $moodlesub;
                    }
                    continue;
                }
                // Ended in Stripe while Moodle still had it as running: catch up now.
                $factory = new stripe_service_factory($config->apikey, $config->secretkey);
                $service = $factory->subscription_service();
                if ($model = $service->find_subscription($moodlesub->subscriptionid)) {
                    $service->cancel_subscription($model, false);
                }
            } catch (ApiErrorException | \moodle_exception $e) {
                debugging('paygw_stripe: cannot check subscription ' . $moodlesub->subscriptionid . ' before a purchase: ' .
                    $e->getMessage(), DEBUG_DEVELOPER);
                return $moodlesub;
            }
        }
        return null;
    }

    /**
     * Where to send the user after a payment (or a cancelled Checkout): the course, or – for a course the
     * user cannot open (hidden subscription course) – "My courses" after a payment and the home page otherwise.
     *
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $userid
     * @param bool $success
     * @return moodle_url
     */
    public static function get_return_url(string $component, string $paymentarea, int $itemid, int $userid,
            bool $success = true): moodle_url {
        global $DB;
        $instance = wsb_metadata::resolve_enrol_instance($component, $itemid);
        if ($instance) {
            $visible = $DB->get_field('course', 'visible', ['id' => $instance->courseid]);
            $canview = $visible !== false && ((int) $visible === 1 || has_capability('moodle/course:viewhiddencourses',
                \context_course::instance($instance->courseid), $userid));
            if (!$canview) {
                return new moodle_url($success ? '/my/courses.php' : '/');
            }
        }
        if ($success) {
            return helper::get_success_url($component, $paymentarea, $itemid);
        }
        return $instance ? new moodle_url('/course/view.php', ['id' => $instance->courseid]) : new moodle_url('/');
    }
}
