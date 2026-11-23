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
 * Tests for purchase availability checks.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

/**
 * Tests for purchase_availability.
 *
 * @covers \paygw_stripe\local\service\purchase_availability
 */
final class purchase_availability_test extends \advanced_testcase {
    /**
     * Create a fee enrolment instance in a new course.
     *
     * @param array $course Course fields
     * @param array $fields Enrolment instance fields
     * @return \stdClass mdl_enrol record
     */
    private function fee_instance(array $course = [], array $fields = []): \stdClass {
        global $DB;
        $this->resetAfterTest();
        set_config('enrol_plugins_enabled', 'manual,fee');
        $course = $this->getDataGenerator()->create_course($course);
        $id = enrol_get_plugin('fee')->add_instance($course, ['cost' => 10, 'currency' => 'USD'] + $fields);
        return $DB->get_record('enrol', ['id' => $id]);
    }

    /**
     * Tests an open enrolment in a visible course can be bought.
     */
    public function test_available(): void {
        $instance = $this->fee_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(purchase_availability::get_unavailable_reason($instance, (int) $user->id));
    }

    /**
     * Tests a hidden course cannot be bought by a student.
     */
    public function test_hidden_course(): void {
        $instance = $this->fee_instance(['visible' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertSame(
            'purchaseunavailable_coursehidden',
            purchase_availability::get_unavailable_reason($instance, (int) $user->id)
        );
    }

    /**
     * Tests a disabled enrolment method cannot be bought.
     */
    public function test_disabled_instance(): void {
        $instance = $this->fee_instance([], ['status' => ENROL_INSTANCE_DISABLED]);
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame(
            'purchaseunavailable_enroldisabled',
            purchase_availability::get_unavailable_reason($instance, (int) $user->id)
        );
    }

    /**
     * Tests the enrolment period is respected.
     */
    public function test_enrolment_period(): void {
        $user = $this->getDataGenerator()->create_user();
        $future = $this->fee_instance([], ['enrolstartdate' => time() + DAYSECS]);
        $this->assertSame(
            'purchaseunavailable_notstarted',
            purchase_availability::get_unavailable_reason($future, (int) $user->id)
        );
        $past = $this->fee_instance([], ['enrolenddate' => time() - DAYSECS]);
        $this->assertSame(
            'purchaseunavailable_ended',
            purchase_availability::get_unavailable_reason($past, (int) $user->id)
        );
    }

    /**
     * Tests an enrolled user does not pay again, and a suspended one is told why.
     */
    public function test_existing_enrolment(): void {
        $instance = $this->fee_instance();
        $plugin = enrol_get_plugin('fee');
        $enrolled = $this->getDataGenerator()->create_user();
        $plugin->enrol_user($instance, $enrolled->id);
        $this->assertSame(
            'purchaseunavailable_alreadyenrolled',
            purchase_availability::get_unavailable_reason($instance, (int) $enrolled->id)
        );

        $suspended = $this->getDataGenerator()->create_user();
        $plugin->enrol_user($instance, $suspended->id, null, 0, 0, ENROL_USER_SUSPENDED);
        $this->assertSame(
            'purchaseunavailable_enrolsuspended',
            purchase_availability::get_unavailable_reason($instance, (int) $suspended->id)
        );

        $expired = $this->getDataGenerator()->create_user();
        $plugin->enrol_user($instance, $expired->id, null, time() - 2 * DAYSECS, time() - DAYSECS);
        $this->assertNull(purchase_availability::get_unavailable_reason($instance, (int) $expired->id));
    }
}
