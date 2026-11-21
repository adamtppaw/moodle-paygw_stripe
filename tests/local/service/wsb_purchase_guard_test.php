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
 * Purchase guards and return URLs (WSB Merito).
 *
 * @package paygw_stripe
 * @covers \paygw_stripe\local\service\wsb_purchase_guard
 * @copyright 2026 WSB Merito
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wsb_purchase_guard_test extends \advanced_testcase {
    /**
     * Create a course with an enabled fee instance.
     *
     * @param array $course Course fields.
     * @param array $instance Enrol instance fields.
     * @return \stdClass mdl_enrol record
     */
    private function fee_instance(array $course = [], array $instance = []): \stdClass {
        global $DB;
        $this->resetAfterTest();
        set_config('enrol_plugins_enabled', 'manual,fee');
        $course = $this->getDataGenerator()->create_course($course);
        $id = enrol_get_plugin('fee')->add_instance($course, $instance + ['cost' => 100, 'currency' => 'PLN']);
        return $DB->get_record('enrol', ['id' => $id]);
    }

    public function test_available_course_can_be_bought(): void {
        $instance = $this->fee_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(wsb_purchase_guard::get_purchase_unavailable_reason($instance, false, (int) $user->id));
    }

    public function test_already_enrolled_and_suspended(): void {
        $instance = $this->fee_instance();
        $plugin = enrol_get_plugin('fee');
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user();
        $plugin->enrol_user($instance, $active->id);
        $plugin->enrol_user($instance, $suspended->id, null, 0, 0, ENROL_USER_SUSPENDED);
        $this->assertSame('purchaseunavailable_alreadyenrolled',
            wsb_purchase_guard::get_purchase_unavailable_reason($instance, false, (int) $active->id));
        $this->assertSame('purchaseunavailable_enrolsuspended',
            wsb_purchase_guard::get_purchase_unavailable_reason($instance, false, (int) $suspended->id));
        // An enrolment that has ended does not count as access.
        $ended = $this->getDataGenerator()->create_user();
        $plugin->enrol_user($instance, $ended->id, null, time() - 2 * DAYSECS, time() - DAYSECS);
        $this->assertNull(wsb_purchase_guard::get_purchase_unavailable_reason($instance, false, (int) $ended->id));
    }

    public function test_hidden_course_only_for_subscription_accounts(): void {
        $instance = $this->fee_instance(['visible' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertSame('purchaseunavailable_coursehidden',
            wsb_purchase_guard::get_purchase_unavailable_reason($instance, false, (int) $user->id));
        $this->assertNull(wsb_purchase_guard::get_purchase_unavailable_reason($instance, true, (int) $user->id));
    }

    public function test_disabled_and_outside_dates(): void {
        global $DB;
        $instance = $this->fee_instance();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $instance->id]);
        $this->assertSame('purchaseunavailable_enroldisabled', wsb_purchase_guard::get_purchase_unavailable_reason(
            $DB->get_record('enrol', ['id' => $instance->id])));

        $future = $this->fee_instance([], ['enrolstartdate' => time() + DAYSECS]);
        $this->assertSame('purchaseunavailable_notstarted', wsb_purchase_guard::get_purchase_unavailable_reason($future));
        $past = $this->fee_instance([], ['enrolenddate' => time() - DAYSECS]);
        $this->assertSame('purchaseunavailable_ended', wsb_purchase_guard::get_purchase_unavailable_reason($past));
    }

    public function test_return_url_for_hidden_course(): void {
        $visible = $this->fee_instance();
        $hidden = $this->fee_instance(['visible' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $url = wsb_purchase_guard::get_return_url('enrol_fee', 'fee', (int) $visible->id, (int) $user->id, false);
        $this->assertSame('/course/view.php', $url->get_path(false));
        $this->assertEquals($visible->courseid, $url->get_param('id'));

        $this->assertSame('/my/courses.php',
            wsb_purchase_guard::get_return_url('enrol_fee', 'fee', (int) $hidden->id, (int) $user->id)->out_as_local_url(false));
        $this->assertSame('/',
            wsb_purchase_guard::get_return_url('enrol_fee', 'fee', (int) $hidden->id, (int) $user->id, false)
                ->out_as_local_url(false));
    }

    public function test_subscription_item_falls_back_to_product_mapping(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('paygw_stripe_products', (object) ['productid' => 'prod_test', 'component' => 'enrol_fee',
            'paymentarea' => 'fee', 'itemid' => 7]);
        $item = wsb_purchase_guard::get_subscription_item((object) ['productid' => 'prod_test', 'component' => null,
            'paymentarea' => null, 'itemid' => null]);
        $this->assertSame(['component' => 'enrol_fee', 'paymentarea' => 'fee', 'itemid' => 7], (array) $item);
        $item = wsb_purchase_guard::get_subscription_item((object) ['productid' => 'prod_other', 'component' => 'enrol_fee',
            'paymentarea' => 'fee', 'itemid' => 9]);
        $this->assertSame(9, $item->itemid);
    }
}
