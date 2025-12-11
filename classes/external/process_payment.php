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
 * External enrol function for stripepayment
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2025 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace enrol_stripepayment\external;
 use core_external\external_api;
 use core_external\external_function_parameters;
 use core_external\external_value;
 use core_external\external_single_structure;
 use enrol_stripepayment\util;
 use moodle_url;

 /**
  * External enrol function for stripepayment
  *
  * @package    enrol_stripepayment
  * @author     DualCube <admin@dualcube.com>
  * @copyright  2025 DualCube Team(https://dualcube.com)
  * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
  */
class process_payment extends external_api {
    /**
     * define parameter type of stripepayment_enrol
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'userid' => new external_value(PARAM_INT, 'Update data user id'),
                'couponid' => new external_value(PARAM_RAW, 'Update coupon id'),
                'instanceid' => new external_value(PARAM_INT, 'Update instance id'),
            ]
        );
    }

    /**
     * return type of stripe js method
     */
    public static function execute_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success or 0 if failure'),
                'redirecturl' => new external_value(PARAM_URL, 'Stripe Checkout URL', VALUE_OPTIONAL),
                'error' => new external_single_structure(
                    [
                        'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
                    ],
                    VALUE_OPTIONAL
                ),
            ]
        );
    }

    /**
     * process payment using stripe checkout session
     *
     * @param int $userid User ID to enroll
     * @param string $couponid Coupon code
     * @param int $instanceid Instance ID
     * @return array
     */
    public static function execute($userid, $couponid, $instanceid) {
        $sessionparams = self::get_session_params($userid, $couponid, $instanceid);
        $session = util::stripe_api_request('checkout_session_create', '', $sessionparams);
        return [
            'status' => 'success',
            'redirecturl' => $session['url'],
            'error' => [],
        ];
    }

    /**
     * Get checkout session params
     *
     * @param int $userid User ID to enroll
     * @param string $couponid Coupon code
     * @param int $instanceid Instance ID
     * @return array
     */
    private static function get_session_params($userid, $couponid, $instanceid) {
        [$plugininstance, $course, $context, $user] = util::validate_data($userid, $instanceid);
        $amount = util::get_stripe_amount($plugininstance->cost, $plugininstance->currency, false);
        $coursename = format_string($course->fullname, true, ['context' => $context]);
        $receiverid = self::get_stripe_customer_id($user);
        $usertoken = util::get_core()->get_config('webservice_token');

        $sessionparams = [
            'customer' => $receiverid,
            'payment_intent_data' => ['description' => get_string('intentdescription', 'enrol_stripepayment', $coursename)],
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'product_data' => [
                        'name' => $coursename,
                        'metadata' => ['pro_id' => $plugininstance->courseid],
                        'description' => get_string('productdescription', 'enrol_stripepayment', $coursename),
                    ],
                    'unit_amount' => $amount,
                    'currency' => $plugininstance->currency,
                ],
                'quantity' => 1,
            ]],
            'discounts' => [['coupon' => $couponid]],
            'metadata' => [
                'course_shortname' => format_string($course->shortname, true, ['context' => $context]),
                'course_id' => $course->id,
                'couponid' => $couponid,
            ],
            'mode' => 'payment',
            'success_url' => new moodle_url('/webservice/rest/server.php', ['wstoken' => $usertoken])
                . '&wsfunction=moodle_stripepayment_process_enrolment'
                . '&moodlewsrestformat=json'
                . '&sessionid={CHECKOUT_SESSION_ID}'
                . '&userid=' . $userid
                . '&couponid=' . $couponid
                . '&instanceid=' . $instanceid,
            'cancel_url' => new moodle_url('/course/view.php', ['id' => $plugininstance->courseid]),
        ];

        return $sessionparams;
    }

    /**
     * Get stripe customer id
     *
     * @param object $user User object
     * @return string
     */
    public static function get_stripe_customer_id($user) {
        global $DB;
        $customerrecord = $DB->get_record('enrol_stripepayment', ['receiveremail' => $user->email], '*', IGNORE_MISSING);
        $receiverid = $customerrecord?->receiverid;

        if ($receiverid) {
            try {
                util::stripe_api_request('customer_retrieve', $receiverid);
            } catch (\Exception $e) {
                $receiverid = null;
            }
        } else {
            $customers = util::stripe_api_request('customer_list', '', [
                'email' => $user->email,
                'limit' => 1,
            ]);
            if (!empty($customers['data'])) {
                $receiverid = $customers['data'][0]['id'] ?? null;
            } else {
                $newcustomer = util::stripe_api_request('customer_create', '', [
                    'email' => $user->email,
                    'name' => fullname($user),
                ]);
                $receiverid = $newcustomer['id'] ?? null;
            }
        }

        return $receiverid;
    }
}
