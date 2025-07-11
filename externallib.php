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

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php"); // Support for previous version of moodle 4.2.
require_once("$CFG->libdir/enrollib.php");
require_once('vendor/stripe/stripe-php/init.php');
use Stripe\Stripe as Stripe;
use Stripe\Coupon as Coupon;
use Stripe\Customer as Customer;
use Stripe\Checkout\Session as Session;
use Stripe\PaymentIntent as PaymentIntent;

/**
 * External library for stripepayment
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_enrol_stripepayment_external extends external_api {

    /**
     * Parameter for couponsettings function
     */
    public static function stripepayment_applycoupon_parameters() {
        return new external_function_parameters(
            [
                'couponid' => new external_value(PARAM_RAW, 'The coupon id to operate on'),
                'instance_id' => new external_value(PARAM_RAW, 'Update instance id'),
            ]
        );
    }

    /**
     * return type of couponsettings functioin
     */
    public static function stripepayment_applycoupon_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success'),
                'coupon_name' => new external_value(PARAM_RAW, 'coupon name', VALUE_OPTIONAL),
                'coupon_type' => new external_value(PARAM_RAW, 'coupon type: percent_off or amount_off', VALUE_OPTIONAL),
                'discount_value' => new external_value(PARAM_RAW, 'discount value', VALUE_OPTIONAL),
                'original_cost' => new external_value(PARAM_RAW, 'original cost before discount', VALUE_OPTIONAL),
                'currency' => new external_value(PARAM_RAW, 'currency code', VALUE_OPTIONAL),
                'discount_amount' => new external_value(PARAM_RAW, 'discount amount', VALUE_OPTIONAL),
                'ui_state' => new external_value(PARAM_RAW, 'UI state: free|paid|error', VALUE_OPTIONAL),
                'error_message' => new external_value(PARAM_RAW, 'error message if any', VALUE_OPTIONAL),
                'show_sections' => new external_single_structure([
                    'free_enrollment' => new external_value(PARAM_BOOL, 'redirct to free enrollment section'),
                    'paid_enrollment' => new external_value(PARAM_BOOL, 'show paid enrollment section'),
                    'discount_section' => new external_value(PARAM_BOOL, 'show discount section'),
                ], 'sections to show/hide', VALUE_OPTIONAL),
                'auto_enrolled' => new external_value(PARAM_BOOL, 'whether user was automatically enrolled', VALUE_OPTIONAL),
            ]
        );
    }

    /**
     * Enhanced function for couponsettings with improved validation
     * @param string $couponid
     * @param int $instanceid
     * @return array
     */
    public static function stripepayment_applycoupon($couponid, $instanceid) {
        global $DB;

        // Enhanced input validation
        if (empty($couponid) || trim($couponid) === '') {
            throw new invalid_parameter_exception('Coupon code cannot be empty');
        }

        if (!is_numeric($instanceid) || $instanceid <= 0) {
            throw new invalid_parameter_exception('Invalid instance ID format');
        }

        $plugin = enrol_get_plugin('stripepayment');
        $plugininstance = $DB->get_record("enrol", ["id" => $instanceid, "status" => 0]);
        if (!$plugininstance) {
            throw new invalid_parameter_exception('Enrollment instance not found or disabled');
        }

        // Validate Stripe configuration
        $secretkey = $plugin->get_config('secretkey');
        if (empty($secretkey)) {
            throw new invalid_parameter_exception('Stripe configuration incomplete');
        }

        $originalcost = (float)$plugininstance->cost > 0 ? (float)$plugininstance->cost : (float)$plugin->get_config('cost');
        $cost = $originalcost;
        $currency = $plugininstance->currency ? $plugininstance->currency : 'USD';

        $cost = format_float($cost, 2, false);
        $originalcost = format_float($originalcost, 2, false);

        Stripe::setApiKey($secretkey);

        $couponname = '';
        $coupontype = '';
        $discountvalue = 0;
        $discountamount = 0;

        try {
            $coupon = Coupon::retrieve($couponid);

            // Enhanced coupon validation
            if (!$coupon || !$coupon->valid) {
                throw new Exception(get_string('invalidcoupon', 'enrol_stripepayment'));
            }

            // Check if coupon has expired
            if (isset($coupon->redeem_by) && $coupon->redeem_by < time()) {
                throw new Exception('Coupon has expired');
            }

            // Check if coupon has usage limits
            if (isset($coupon->max_redemptions) && isset($coupon->times_redeemed) &&
                $coupon->times_redeemed >= $coupon->max_redemptions) {
                throw new Exception('Coupon usage limit exceeded');
            }

            $couponname = isset($coupon->name) ? $coupon->name : $couponid;

            if (isset($coupon->percent_off)) {
                $discountamount = $cost * ($coupon->percent_off / 100);
                $cost -= $discountamount;
                $coupontype = 'percent_off';
                $discountvalue = $coupon->percent_off;
            } else if (isset($coupon->amount_off)) {
                // Ensure currency matches
                if (isset($coupon->currency) && strtoupper($coupon->currency) !== strtoupper($currency)) {
                    throw new Exception('Coupon currency does not match course currency');
                }
                $discountamount = $coupon->amount_off / 100;
                $cost -= $discountamount;
                $coupontype = 'amount_off';
                $discountvalue = $coupon->amount_off / 100;
            } else {
                throw new Exception('Invalid coupon type');
            }

            // Ensure cost doesn't go negative
            $cost = max(0, $cost);
            $cost = format_float($cost, 2, false);
            $discountamount = format_float($discountamount, 2, false);

        } catch (Exception $e) {
            // Log the error for debugging
            error_log('Stripe coupon validation failed: ' . $e->getMessage());
            throw new invalid_parameter_exception($e->getMessage());
        }

        // Calculate UI state for display purposes only
        $uistate = [
            'state' => $cost <= 0 ? 'free' : 'paid',
            'error_message' => '',
            'show_sections' => [
                'free_enrollment' => $cost <= 0,
                'paid_enrollment' => $cost > 0,
                'discount_section' => ($discountamount > 0)
            ]
        ];

        // Auto-enroll user if cost is 0 or less (free enrollment)
        $auto_enrolled = false;
        if ($uistate['state'] === 'free') {
            global $USER;
            try {
                // Check if user is already enrolled to prevent duplicate enrollments
                if (!$DB->record_exists('user_enrolments', ['userid' => $USER->id, 'enrolid' => $instanceid])) {
                    // Call the existing free enrollment method
                    self::stripepayment_free_enrol($USER->id, $couponid, $instanceid);
                    $auto_enrolled = true;
                } else {
                    // User is already enrolled, just mark as auto-enrolled for UI purposes
                    $auto_enrolled = true;
                }
            } catch (Exception $e) {
                // If auto-enrollment fails, log the error but don't break the coupon application
                error_log('Auto free enrollment failed: ' . $e->getMessage());
            }
        }

        return [
            'status' => $cost,
            'coupon_name' => $couponname,
            'coupon_type' => $coupontype,
            'discount_value' => $discountvalue,
            'original_cost' => $originalcost,
            'currency' => $currency,
            'discount_amount' => $discountamount,
            'ui_state' => $uistate['state'],
            'error_message' => $uistate['error_message'],
            'show_sections' => $uistate['show_sections'],
            'auto_enrolled' => $auto_enrolled,
        ];
    }

    /**
     * declare parameters type for stripepayment_free_enrol
     */
    public static function stripepayment_free_enrol_parameters() {
        return new external_function_parameters(
            [
                'user_id' => new external_value(PARAM_RAW, 'Update data user id'),
                'couponid' => new external_value(PARAM_RAW, 'Update data coupon id'),
                'instance_id' => new external_value(PARAM_RAW, 'Update data instance id'),
            ],
        );
    }

    /**
     * declare return type for stripepayment_free_enrol
     */
    public static function stripepayment_free_enrol_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success'),
            ]
        );
    }

    /**
     * for free enrolsetting
     * @param number $userid
     * @param number $userid
     * @param number $instanceid
     */
    public static function stripepayment_free_enrol($userid, $couponid, $instanceid) {
        global $DB, $CFG;

        $validateddata = self::validate_data( $userid, $instanceid);
        $plugininstance = $validateddata[0];
        $course = $validateddata[1];
        $context = $validateddata[2];
        $user = $validateddata[3];

        // Prepare enrollment data for free enrollment
        $data = new stdClass();
        $data->couponid = $couponid;
        $data->userid = $userid;
        $data->instanceid = $instanceid;
        $data->stripeEmail = $user->email;
        $data->courseid = $plugininstance->courseid;
        $data->timeupdated = time();
        $data->receiver_email = $user->email;
        $data->payment_status = 'succeeded';
        $data->receiver_id = 'free_enrollment_' . time(); // Use a placeholder ID for free enrollments.

        // Use consolidated enrollment and notification function
        self::enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data);

        $result = ['status' => 'working'];
        return $result;
    }

    /**
     * Consolidated enrollment and notification function
     * Handles user enrollment and sends notifications to students, teachers, and admins
     *
     * @param stdClass $plugininstance The enrollment instance
     * @param stdClass $course The course object
     * @param stdClass $context The course context
     * @param stdClass $user The user to enroll
     * @param stdClass $enrollmentdata The enrollment data to insert into enrol_stripepayment table
     * @return bool Success status
     */
    private static function enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $enrollmentdata) {
        global $DB, $CFG;

        $plugin = enrol_get_plugin('stripepayment');

        // Insert enrollment record
        $DB->insert_record("enrol_stripepayment", $enrollmentdata);

        // Calculate enrollment period
        if ($plugininstance->enrolperiod) {
            $timestart = time();
            $timeend = $timestart + $plugininstance->enrolperiod;
        } else {
            $timestart = time();
            $timeend = 0;
        }

        // Enroll user
        $plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);

        // Send notifications (same logic for both free and paid enrollment)
        self::send_enrollment_notifications($course, $context, $user, $plugin);

        return true;
    }

    /**
     * Send enrollment notifications to students, teachers, and admins
     *
     * @param stdClass $course The course object
     * @param stdClass $context The course context
     * @param stdClass $user The enrolled user
     * @param object $plugin The enrollment plugin instance
     */
    private static function send_enrollment_notifications($course, $context, $user, $plugin) {
        global $CFG;

        // Get teacher
        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                                                 '', '', '', '', false, true)) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }

        // Get notification settings
        $mailstudents = $plugin->get_config('mailstudents');
        $mailteachers = $plugin->get_config('mailteachers');
        $mailadmins   = $plugin->get_config('mailadmins');

        // Prepare common data
        $shortname = format_string($course->shortname, true, ['context' => $context]);
        $coursecontext = context_course::instance($course->id);
        $orderdetails = new stdClass();
        $orderdetails->coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
        $orderdetails->course = format_string($course->fullname, true, ['context' => $coursecontext]);
        $subject = get_string("enrolmentnew", 'enrol', $shortname);
        $orderdetails->user = fullname($user);

        // Send notification to student
        if (!empty($mailstudents)) {
            $orderdetails->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";
            $userfrom = empty($teacher) ? core_user::get_noreply_user() : $teacher;
            $fullmessage = get_string('welcometocoursetext', '', $orderdetails);
            $fullmessagehtml = '<p>'.get_string('welcometocoursetext', '', $orderdetails).'</p>';

            $message = new \core\message\message();
            $message->courseid = $course->id;
            $message->component = 'enrol_stripepayment';
            $message->name = 'stripepayment_enrolment';
            $message->userfrom = $userfrom;
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $fullmessage;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $fullmessagehtml;
            $message->smallmessage = get_string('enrolmentnew', 'enrol', $shortname);
            $message->notification = 1;
            $message->contexturl = new \moodle_url('/course/view.php', ['id' => $course->id]);
            $message->contexturlname = $orderdetails->coursename;

            $messageid = message_send($message);
            if (!$messageid) {
                debugging('Failed to send stripepayment enrolment notification to student: ' . $user->id, DEBUG_DEVELOPER);
            }
        }

        // Send notification to teacher
        if (!empty($mailteachers) && !empty($teacher)) {
            $fullmessage = get_string('enrolmentnewuser', 'enrol', $orderdetails);
            $fullmessagehtml = '<p>'.get_string('enrolmentnewuser', 'enrol', $orderdetails).'</p>';

            $message = new \core\message\message();
            $message->courseid = $course->id;
            $message->component = 'enrol_stripepayment';
            $message->name = 'stripepayment_enrolment';
            $message->userfrom = $user;
            $message->userto = $teacher;
            $message->subject = $subject;
            $message->fullmessage = $fullmessage;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $fullmessagehtml;
            $message->smallmessage = get_string('enrolmentnew', 'enrol', $shortname);
            $message->notification = 1;
            $message->contexturl = new \moodle_url('/course/view.php', ['id' => $course->id]);
            $message->contexturlname = $orderdetails->coursename;

            $messageid = message_send($message);
            if (!$messageid) {
                debugging('Failed to send stripepayment enrolment notification to teacher: ' . $teacher->id, DEBUG_DEVELOPER);
            }
        }

        // Send notification to admins
        if (!empty($mailadmins)) {
            $admins = get_admins();
            foreach ($admins as $admin) {
                $fullmessage = get_string('enrolmentnewuser', 'enrol', $orderdetails);
                $fullmessagehtml = '<p>'.get_string('enrolmentnewuser', 'enrol', $orderdetails).'</p>';

                $message = new \core\message\message();
                $message->courseid = $course->id;
                $message->component = 'enrol_stripepayment';
                $message->name = 'stripepayment_enrolment';
                $message->userfrom = $user;
                $message->userto = $admin;
                $message->subject = $subject;
                $message->fullmessage = $fullmessage;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = $fullmessagehtml;
                $message->smallmessage = get_string('enrolmentnew', 'enrol', $shortname);
                $message->notification = 1;
                $message->contexturl = new \moodle_url('/course/view.php', ['id' => $course->id]);
                $message->contexturlname = $orderdetails->coursename;

                $messageid = message_send($message);
                if (!$messageid) {
                    debugging('Failed to send stripepayment enrolment notification to admin: ' . $admin->id, DEBUG_DEVELOPER);
                }
            }
        }
    }





    /**
     * define parameter type of stripepayment_paid_enrol
     */
    public static function stripepayment_paid_enrol_parameters() {
        return new external_function_parameters(
            [
                'user_id' => new external_value(PARAM_RAW, 'Update data user id'),
                'couponid' => new external_value(PARAM_RAW, 'Update coupon id'),
                'instance_id' => new external_value(PARAM_RAW, 'Update instance id'),
            ],
        );
    }

    /**
     * return type of stripe js method
     */
    public static function stripepayment_paid_enrol_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success'),
            ]
        );
    }

    /**
     * Enhanced function for create Checkout Session and process payment
     * @param int $userid
     * @param string $couponid
     * @param int $instanceid
     * @return array
     */
    public static function stripepayment_paid_enrol($userid, $couponid, $instanceid ) {
        global $CFG, $DB;

        // Enhanced input validation
        if (!is_numeric($userid) || $userid <= 0) {
            return [
                'status' => 0,
                'error' => ['message' => 'Invalid user ID'],
            ];
        }

        if (!is_numeric($instanceid) || $instanceid <= 0) {
            return [
                'status' => 0,
                'error' => ['message' => 'Invalid instance ID'],
            ];
        }

        $plugin = enrol_get_plugin('stripepayment');
        $secretkey = $plugin->get_config('secretkey');
        $usertoken = $plugin->get_config('webservice_token');

        // Validate Stripe configuration
        if (empty($secretkey)) {
            return [
                'status' => 0,
                'error' => ['message' => 'Stripe configuration incomplete'],
            ];
        }

        // Validate users, course, context, plugininstance.
        try {
            $validateddata = self::validate_data($userid, $instanceid);
            $plugininstance = $validateddata[0];
            $course = $validateddata[1];
            $context = $validateddata[2];
            $user = $validateddata[3];
        } catch (Exception $e) {
            return [
                'status' => 0,
                'error' => ['message' => 'Validation failed: ' . $e->getMessage()],
            ];
        }

        // Calculate final cost after coupon application and retrieve coupon details
        $finalcost = $plugininstance->cost;
        $couponobject = null;
        $usestripecouppndiscount = false; // Flag to determine if we should let Stripe handle the discount

        if (!empty($couponid)) {
            try {
                // Retrieve the actual coupon object from Stripe first
                Stripe::setApiKey($secretkey);
                $couponobject = Coupon::retrieve($couponid);

                // For repeating/forever coupons, let Stripe handle the discount
                // For once coupons, we calculate the discount ourselves
                if ($couponobject && $couponobject->valid &&
                    ($couponobject->duration === 'repeating' || $couponobject->duration === 'forever')) {
                    $usestripecouppndiscount = true;
                    // Don't apply discount here, let Stripe handle it
                    $finalcost = $plugininstance->cost;
                } else {
                    // For 'once' coupons, calculate discount ourselves
                    $coupondata = self::stripepayment_applycoupon($couponid, $instanceid);
                    $finalcost = $coupondata['status']; // This contains the final cost after discount
                }
            } catch (Exception $e) {
                // If coupon validation fails, use original cost
                $finalcost = $plugininstance->cost;
                $couponobject = null;
                $usestripecouppndiscount = false;
            }
        }

        $amount = $plugin->get_stripe_amount($finalcost, $plugininstance->currency, false);
        $courseid = $plugininstance->courseid;
        $currency = $plugininstance->currency;
        $description  = format_string($course->fullname, true, ['context' => $context]);
        $shortname = format_string($course->shortname, true, ['context' => $context]);
        if (empty($secretkey) || empty($courseid) || empty($amount) || empty($currency) || empty($description)) {
            redirect($CFG->wwwroot.'/course/view.php?id='.$courseid);
        } else {
            // Set API key.
            Stripe::setApiKey($secretkey);
            $response = [
                'status' => 0,
                'error' => [
                    'message' => get_string('invalidrequest', 'enrol_stripepayment'),
                ],
            ];
            // Retrieve Stripe customer_id if previously set.
            $checkcustomer = $DB->get_records('enrol_stripepayment',
            ['receiver_email' => $user->email]);
            $receiveremail = $user->email;
            foreach ($checkcustomer as $keydata => $valuedata) {
                $checkcustomer = $valuedata;
            }
            if ($checkcustomer) {
                $receiverid = $checkcustomer->receiver_id;
                $receiveremail = null;   // Must not be set if customer id provided.
            } else {
                $customers = Customer::all(['email' => $user->email]);
                if ( empty($customers->data) ) {
                    $customer = Customer::create([
                        "email" => $user->email,
                        "description" => get_string('charge_description1', 'enrol_stripepayment'),
                    ]);
                } else {
                    $customer = $customers->data[0];
                }
                $receiverid = $customer->id;
            }
            // Create new Checkout Session for the order.
            try {
                $sessionparams = [
                    'customer_email' => $receiveremail,
                    'payment_intent_data' => ['description' => $description ],
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'product_data' => [
                                'name' => $description,
                                'metadata' => [
                                    'pro_id' => $courseid,
                                ],
                                'description' => $description,
                            ],
                            'unit_amount' => $amount,
                            'currency' => $currency,
                        ],
                        'quantity' => 1,
                    ]],

                    'metadata' => [
                        'course_shortname' => $shortname,
                        'course_id' => $course->id,
                        'couponid' => $couponid,
                        'coupon_name' => $couponobject && isset($couponobject->name) ? $couponobject->name : '',
                    ],
                    'mode' => 'payment',
                    'success_url' => $CFG->wwwroot . '/webservice/rest/server.php?wstoken=' . $usertoken .
                    '&wsfunction=moodle_stripepayment_success_stripe_url' .
                    '&moodlewsrestformat=json' .
                    '&sessionid={CHECKOUT_SESSION_ID}' .
                    '&user_id=' . $userid .
                    '&couponid=' . $couponid .
                    '&instance_id=' . $instanceid,
                    'cancel_url' => $CFG->wwwroot . '/course/view.php?id=' . $courseid,
                ];

                // Add coupon discount information to the session only for repeating/forever coupons
                // For 'once' coupons, we already calculated the discount in the amount
                if (!empty($couponid) && $couponobject && $couponobject->valid && $usestripecouppndiscount) {
                    $sessionparams['discounts'] = [['coupon' => $couponid]];
                }

                $session = Session::create($sessionparams);
            } catch (Exception $e) {
                $apierror = $e->getMessage();
            }
            if (empty($apierror) && $session) {
                $response = [
                    'status' => 1,
                    'message' => get_string('sessioncreated', 'enrol_stripepayment'),
                    'sessionId' => $session['id'],
                ];
            } else {
                $response = [
                    'status' => 0,
                    'error' => [
                        'message' => get_string('sessioncreatefail', 'enrol_stripepayment') .$apierror,
                    ],
                ];
            }
            // Return response.
            $passsessionid = isset($response['sessionId']) && !empty($response['sessionId']) ? $response['sessionId'] : '';
            $result = [];
            $result['status'] = $passsessionid;
            return $result;
            die;
        }
    }
    /**
     * function for define parameter type for success_stripe_url
     */
    public static function success_stripe_url_parameters() {
        return new external_function_parameters(
            [
                'sessionid' => new external_value(PARAM_RAW, 'The item id to operate on'),
                'user_id' => new external_value(PARAM_RAW, 'Update data user id'),
                'couponid'  => new external_value(PARAM_RAW, 'The item id to operate coupon id'),
                'instance_id'  => new external_value(PARAM_RAW, 'The item id to operate instance id'),
            ]
        );
    }
    /**
     * function for define return type for success_stripe_url
     */
    public static function success_stripe_url_returns() {
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
    public static function success_stripe_url($sessionid, $userid, $couponid, $instanceid) {
        global $DB, $CFG, $PAGE, $OUTPUT;
        $data = new stdClass();
        $plugin = enrol_get_plugin('stripepayment');
        $secretkey = $plugin->get_config('secretkey');
        Stripe::setApiKey($secretkey);
        $checkoutsession = Session::retrieve($sessionid);
        $charge = PaymentIntent::retrieve($checkoutsession->payment_intent);
        $data->couponid = $couponid;
        $data->stripeEmail = $charge->receipt_email;
        $data->receiver_id = $charge->customer;

        // Validate users, course, conntext, plugininstance.
        $validateddata = self::validate_data( $userid, $instanceid);
        $plugininstance = $validateddata[0];
        $course = $validateddata[1];
        $context = $validateddata[2];
        $user = $validateddata[3];
        $courseid = $plugininstance->courseid;
        $data->courseid = $courseid;
        $data->instanceid = $instanceid;
        $data->userid = (int)$userid;
        $data->timeupdated = time();

        if ( $checkoutsession->payment_status !== 'paid') {
            self::message_stripepayment_error_to_admin("Payment status: ".$checkoutsession->payment_status, $data);
            redirect($CFG->wwwroot);
        }
        $PAGE->set_context($context);
        try {
            // Send the file, this line will be reached if no error was thrown above.
            if (!isset($charge->failure_message) || is_null($charge->failure_message)) {
                $charge->failure_message = 'NA';
            }
            if (!isset($charge->failure_code) || is_null($charge->failure_code)) {
                $charge->failure_code = 'NA';
            }
            $data->receiver_email = $checkoutsession->customer_details->email;
            $data->txn_id = $charge->id;
            $data->tax = $charge->amount / 100;
            $data->memo = $charge->payment_method;
            $data->payment_status = $charge->status;
            $data->pending_reason = $charge->failure_message;
            $data->reason_code = $charge->failure_code;
            $data->item_name = $course->fullname;

            // Use consolidated enrollment and notification function
            self::enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data);
            $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
            $fullname = format_string($course->fullname, true, ['context' => $context]);
            if (is_enrolled($context, $user, '', true)) {
                redirect($destination, get_string('paymentthanks', '', $fullname));
            } else {
                // Somehow they aren't enrolled yet!
                $PAGE->set_url($destination);
                echo $OUTPUT->header();
                $orderdetails = new stdClass();
                $orderdetails->teacher = get_string('defaultcourseteacher');
                $orderdetails->fullname = $fullname;
                notice(get_string('paymentsorry', '', $orderdetails), $destination);
            }
        } catch (Exception $e) {
            self::message_stripepayment_error_to_admin($e->getMessage(), ['sessionid' => $sessionid]);
            throw new invalid_parameter_exception($e->getMessage());
        }
    }

    /**
     * validate plugininstance, course, user, context if validate then ok
     * else send message to admin
     */
    public static function validate_data($userid, $instanceid ) {
        global $DB, $CFG;

        // Validate enrolment instance.
        if (! $plugininstance = $DB->get_record("enrol", ["id" => $instanceid, "status" => 0])) {
            self::message_stripepayment_error_to_admin(get_string('invalidinstance', 'enrol_stripepayment'), $data);
            redirect($CFG->wwwroot);
        }

        // Validate course.
        if (! $course = $DB->get_record("course", ["id" => $plugininstance->courseid])) {
            self::message_stripepayment_error_to_admin(get_string('invalidcourseid', 'enrol_stripepayment'), $data);
            redirect($CFG->wwwroot);
        }

        // Validate context.
        if (! $context = context_course::instance($course->id, IGNORE_MISSING)) {
            self::message_stripepayment_error_to_admin(get_string('invalidcontextid', 'enrol_stripepayment'), $data);
            redirect($CFG->wwwroot);
        }

        // Validate user.
        if (! $user = $DB->get_record("user", ["id" => $userid])) {
            self::message_stripepayment_error_to_admin("Not orderdetails valid user id", $data);
            redirect($CFG->wwwroot.'/course/view.php?id='.$course->id);
        }
        return [$plugininstance, $course, $context, $user];
    }

    /**
     * send error message to admin using Message API
     * @param string  $subject
     * @param array $data
     */
    public static function message_stripepayment_error_to_admin($subject, $data) {
        global $PAGE;
        $PAGE->set_context(context_system::instance());

        $admin = get_admin();
        $site = get_site();
        $messagebody = "$site->fullname:  Transaction failed.\n\n$subject\n\n";
        foreach ($data as $key => $value) {
            $messagebody .= s($key) ." => ". s($value)."\n";
        }
        $messagesubject = "STRIPE PAYMENT ERROR: ".$subject;
        $fullmessage = $messagebody;
        $fullmessagehtml = '<p>'.nl2br(s($messagebody)).'</p>';

        // Send message using Message API.
        $message = new \core\message\message();
        $message->courseid = SITEID;
        $message->component = 'enrol_stripepayment';
        $message->name = 'stripepayment_enrolment';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $admin;
        $message->subject = $messagesubject;
        $message->fullmessage = $fullmessage;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $fullmessagehtml;
        $message->smallmessage = 'Stripe payment error occurred';
        $message->notification = 1;
        $message->contexturl = new \moodle_url('/admin/index.php');
        $message->contexturlname = 'Site administration';

        $messageid = message_send($message);
        if (!$messageid) {
            debugging('Failed to send stripepayment error notification to admin: ' . $admin->id, DEBUG_DEVELOPER);
        }
    }
}
