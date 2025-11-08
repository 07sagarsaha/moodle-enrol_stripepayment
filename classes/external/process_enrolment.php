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
 * External process payment for stripepayment
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace enrol_stripepayment\external;
 use context_course;
 use core\exception\invalid_parameter_exception;
 use core_external\external_api;
 use core_external\external_function_parameters;
 use core_external\external_value;
 use core_external\external_single_structure;
 use core_user;
 use enrol_stripepayment\util;
 use Exception;
 use stdClass;

 /**
  * External process payment for stripepayment
  *
  * @package    enrol_stripepayment
  * @author     DualCube <admin@dualcube.com>
  * @copyright  2019 DualCube Team(https://dualcube.com)
  * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
  */
class process_enrolment extends external_api {
    /**
     * function for define parameter type for process_payment
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'sessionid' => new external_value(PARAM_RAW, 'The item id to operate on'),
                'userid' => new external_value(PARAM_RAW, 'Update data user id'),
                'couponid'  => new external_value(PARAM_RAW, 'The item id to operate coupon id'),
                'instanceid'  => new external_value(PARAM_RAW, 'The item id to operate instance id'),
            ]
        );
    }

    /**
     * function for define return type for process_payment
     */
    public static function execute_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success'),
            ]
        );
    }

    /**
     * after creating checkout charge the payment intent and after payment enrol the student to the course
     * @param number $sessionid
     * @param number $userid
     * @param number $couponid
     * @param number $instanceid
     */
    public static function execute($sessionid, $userid, $couponid, $instanceid) {
        global $DB, $CFG, $PAGE, $OUTPUT;
        $data = new stdClass();

        $checkoutsession = util::stripe_api_request('GET', 'checkout_session_retrieve', [], $sessionid);

        // For 100% discount, no payment_intent is created.
        if (!empty($checkoutsession['payment_intent'])) {
            $charge = util::stripe_api_request('GET', 'payment_intent_retrieve', [], $checkoutsession['payment_intent']);
            $email = $charge['charges']['data'][0]['receipt_email'] ?? ($checkoutsession['customer_details']['email'] ?? '');
            $paymentstatus = $charge['status'];
            $txnid = $charge['id'];
        } else {
            // Free checkout session (0 amount, no PaymentIntent).
            $charge = null;
            $email = $checkoutsession['customer_details']['email'] ?? '';
            $paymentstatus = $checkoutsession['payment_status'];
            $txnid = $checkoutsession['id'];
        }

        $data->couponid = $couponid;
        $data->stripeEmail = $email;

        // Validate users, course, context, plugininstance.
        $validateddata = util::validate_data($userid, $instanceid);
        $plugininstance = $validateddata[0];
        $course = $validateddata[1];
        $context = $validateddata[2];
        $user = $validateddata[3];
        $courseid = $plugininstance->courseid;
        $data->courseid = $courseid;
        $data->instanceid = $instanceid;
        $data->userid = (int)$userid;
        $data->timeupdated = time();

        if ($checkoutsession['payment_status'] !== 'paid') {
            util::message_stripepayment_error_to_admin("Payment status: " . $checkoutsession['payment_status'], $data);
            redirect($CFG->wwwroot);
        }
        $PAGE->set_context($context);
        try {
            // Send the file, this line will be reached if no error was thrown above.
            $failuremessage = $charge ? ($charge['last_payment_error']['message'] ?? 'NA') : 'NA';
            $failurecode = $charge ? ($charge['last_payment_error']['code'] ?? 'NA') : 'NA';
            $data->couponid = $couponid;
            $data->receiveremail = $user->email; // Use user email from database instead of Stripe response.
            $data->receiverid = $checkoutsession['customer'];
            $data->txnid = $txnid;
            $data->price = $charge ? $charge['amount'] / 100 : 0;
            $data->memo = $charge ? ($charge['payment_method'] ?? 'none') : 'none';
            $data->paymentstatus = $paymentstatus;
            $data->pendingreason = $failuremessage;
            $data->reasoncode = $failurecode;
            $data->itemname = $course->fullname;
            $data->paymenttype = $charge ? 'stripe' : 'free';

            // Use consolidated enrollment and notification function.
            self::enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data);
            $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
            $fullname = format_string($course->fullname, true, ['context' => $context]);
            if (is_enrolled($context, $user, '', true)) {
                redirect($destination, get_string('paymentthanks', '', $fullname));
            } else {
                // Somehow they aren't enrolled yet!.
                $PAGE->set_url($destination);
                echo $OUTPUT->header();
                $orderdetails = new stdClass();
                $orderdetails->teacher = get_string('defaultcourseteacher');
                $orderdetails->fullname = $fullname;
                notice(get_string('paymentsorry', '', $orderdetails), $destination);
            }
        } catch (Exception $e) {
            util::message_stripepayment_error_to_admin($e->getMessage(), ['sessionid' => $sessionid]);
            throw new invalid_parameter_exception($e->getMessage());
        }
    }

    /**
     * Enrollment and notification function
     * @param stdClass $plugininstance The enrollment instance
     * @param stdClass $course The course object
     * @param stdClass $context The course context
     * @param stdClass $user The user to enroll
     * @param stdClass $enrollmentdata The enrollment data to insert into enrol_stripepayment table
     * @return bool Success status
     */
    private static function enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $enrollmentdata) {
        global $DB;

        // Insert enrollment record.
        $DB->insert_record("enrol_stripepayment", $enrollmentdata);

        // Calculate enrollment period.
        if ($plugininstance->enrolperiod) {
            $timestart = time();
            $timeend = $timestart + $plugininstance->enrolperiod;
        } else {
            $timestart = time();
            $timeend = 0;
        }

        // Enroll user.
        util::get_core()->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);

        // Send notifications (same logic for both free and paid enrollment).
        self::send_enrollment_notifications($course, $context, $user, util::get_core());

        return true;
    }

    /**
     * Send enrollment notifications to students, teachers, and admins
     * @param stdClass $course The course object
     * @param stdClass $context The course context
     * @param stdClass $user The enrolled user
     * @param object $plugin The enrollment plugin instance
     */
    private static function send_enrollment_notifications($course, $context, $user, $plugin) {
        global $CFG;

        // Get teacher.
        if (
            $users = get_users_by_capability(
                $context,
                'moodle/course:update',
                'u.*',
                'u.id ASC',
                '',
                '',
                '',
                '',
                false,
                true
            )
        ) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }

        // Get notification settings.
        $mailstudents = $plugin->get_config('mailstudents');
        $mailteachers = $plugin->get_config('mailteachers');
        $mailadmins   = $plugin->get_config('mailadmins');

        // Prepare common data.
        $shortname = format_string($course->shortname, true, ['context' => $context]);
        $coursecontext = context_course::instance($course->id);
        $orderdetails = new \stdClass();
        $orderdetails->coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
        $orderdetails->course = format_string($course->fullname, true, ['context' => $coursecontext]);
        $orderdetails->user = fullname($user);
        $sitename = $CFG->wwwroot;

        // Student notification.
        if (!empty($mailstudents)) {
            $orderdetails->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";
            $userfrom = empty($teacher) ? core_user::get_noreply_user() : $teacher;
            $fullmessage = get_string(
                'welcometocoursetext',
                'enrol_stripepayment',
                [
                    'course' => $course->fullname,
                    'sitename' => $sitename,
                ]
            );
            $fullmessagehtml = '<p>' . $fullmessage . '</p>';
            $subject = get_string("enrolmentuser", 'enrol_stripepayment', $shortname);
            util::send_message(
                $course,
                $userfrom,
                $user,
                $subject,
                $orderdetails,
                $shortname,
                $fullmessage,
                $fullmessagehtml
            );
        }

        // Teacher notification.
        if (!empty($mailteachers) && !empty($teacher)) {
            $fullmessage = get_string(
                'adminmessage',
                'enrol_stripepayment',
                [
                    'username' => fullname($user),
                    'course' => $course->fullname,
                    'sitename' => $sitename,
                ]
            );
            $fullmessagehtml = '<p>' . $fullmessage . '</p>';
            $subject = get_string(
                "enrolmentnew",
                'enrol_stripepayment',
                [
                    'username' => fullname($user),
                    'course' => $course->fullname,
                ]
            );
            util::send_message(
                $course,
                $user,
                $teacher,
                $subject,
                $orderdetails,
                $shortname,
                $fullmessage,
                $fullmessagehtml
            );
        }

        // Admin notifications.
        if (!empty($mailadmins)) {
            $admins = get_admins();
            $fullmessage = get_string(
                'adminmessage',
                'enrol_stripepayment',
                [
                    'username' => fullname($user),
                    'course' => $course->fullname,
                    'sitename' => $sitename,
                ]
            );
            $fullmessagehtml = '<p>' . $fullmessage . '</p>';
            $subject = get_string(
                "enrolmentnew",
                'enrol_stripepayment',
                [
                    'username' => fullname($user),
                    'course' => $course->fullname,
                ]
            );
            util::send_message(
                $course,
                $user,
                $admins,
                $subject,
                $orderdetails,
                $shortname,
                $fullmessage,
                $fullmessagehtml
            );
        }
    }
}
