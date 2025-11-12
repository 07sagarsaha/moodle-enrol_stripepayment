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
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace enrol_stripepayment\external;
 use core_external\external_api;
 use core_external\external_function_parameters;
 use core_external\external_value;
 use core_external\external_single_structure;
 use enrol_stripepayment\util;
 use Exception;

 /**
  * External enrol function for stripepayment
  *
  * @package    enrol_stripepayment
  * @author     DualCube <admin@dualcube.com>
  * @copyright  2019 DualCube Team(https://dualcube.com)
  * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
  */
class process_payment extends external_api {
    /**
     * define parameter type of stripepayment_enrol
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'userid' => new external_value(PARAM_RAW, 'Update data user id'),
                'couponid' => new external_value(PARAM_RAW, 'Update coupon id'),
                'instanceid' => new external_value(PARAM_RAW, 'Update instance id'),
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
     * Function for create Checkout Session and process payment
     * @param int $userid
     * @param string $couponid
     * @param int $instanceid
     * @return array
     */
    public static function execute($userid, $couponid, $instanceid) {
        global $CFG, $DB;

        // Input validation.
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

        $secretkey = util::get_current_secret_key();
        $usertoken = util::get_core()->get_config('webservice_token');

        // Validate Stripe configuration.
        if (empty($secretkey)) {
            return [
                'status' => 0,
                'error' => ['message' => 'Stripe configuration incomplete'],
            ];
        }

        // Validate users, course, context, plugininstance.
        try {
            $validateddata = util::validate_data($userid, $instanceid);
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

        // Calculate final cost after coupon application and retrieve coupon details.
        $finalcost = $plugininstance->cost;
        $amount = util::get_stripe_amount($finalcost, $plugininstance->currency, false);
        $courseid = $plugininstance->courseid;
        $currency = $plugininstance->currency;
        $description  = format_string($course->fullname, true, ['context' => $context]);
        if (empty($secretkey) || empty($courseid) || empty($amount) || empty($currency) || empty($description)) {
            redirect($CFG->wwwroot . '/course/view.php?id=' . $courseid);
        } else {
            $response = [
                'status' => 0,
                'error' => [
                    'message' => get_string('invalidrequest', 'enrol_stripepayment'),
                ],
            ];
            // Retrieve Stripe customer_id if previously set.
            $checkcustomer = $DB->get_record('enrol_stripepayment', ['receiveremail' => $user->email], '*', IGNORE_MISSING);
            $receiverid = $checkcustomer ? $checkcustomer->receiverid : null;

            if ($receiverid) {
                try {
                    // Attempt to retrieve customer with the existing ID.
                    util::stripe_api_request('customer_retrieve', $receiverid);
                } catch (Exception $e) {
                    if (
                        strpos($e->getMessage(), 'No such customer') !== false
                        || strpos($e->getMessage(), 'You do not have access') !== false
                    ) {
                        // Customer doesn't exist or inaccessible with current API key.
                        $receiverid = null;
                    } else {
                        throw $e; // Some other error, rethrow.
                    }
                }
            } else {
                try {
                    $customers = util::stripe_api_request('customer_list', '', ['email' => $user->email]);
                    if (!empty($customers['data'])) {
                        $receiverid = $customers['data'][0]['id'];
                    } else {
                        $newcustomer = util::stripe_api_request(
                            'customer_create',
                            'customer',
                            [
                                'email' => $user->email,
                                'name' => fullname($user),
                            ]
                        );
                        $receiverid = $newcustomer['id'];
                    }

                    if ($checkcustomer) {
                        $DB->set_field('enrol_stripepayment', 'receiverid', $receiverid, ['receiveremail' => $user->email]);
                    } else {
                        // Save a new minimal record to store receiverid for this user.
                        $DB->insert_record('enrol_stripepayment', [
                            'receiveremail' => $user->email,
                            'receiverid' => $receiverid,
                            'userid' => $user->id,
                            'timeupdated' => time(),
                        ]);
                    }
                } catch (Exception $e) {
                    return [
                        'status' => 0,
                        'error' => ['message' => 'Could not create customer in Stripe: ' . $e->getMessage()],
                    ];
                }
            }

            // Create new Checkout Session for the order.
            try {
                $sessionparams = [
                    'customer' => $receiverid,
                    'payment_intent_data' => ['description' => $description],
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
                    'discounts' => [['coupon' => $couponid]],
                    'metadata' => [
                        'course_shortname' => format_string($course->shortname, true, ['context' => $context]),
                        'course_id' => $course->id,
                        'couponid' => $couponid,
                    ],
                    'mode' => 'payment',
                    'success_url' => $CFG->wwwroot . '/webservice/rest/server.php?wstoken=' . $usertoken
                        . '&wsfunction=moodle_stripepayment_process_enrolment'
                        . '&moodlewsrestformat=json'
                        . '&sessionid={CHECKOUT_SESSION_ID}'
                        . '&userid=' . $userid
                        . '&couponid=' . $couponid
                        . '&instanceid=' . $instanceid,
                    'cancel_url' => $CFG->wwwroot . '/course/view.php?id=' . $courseid,
                ];

                $session = util::stripe_api_request('checkout_session_create', '', $sessionparams);
            } catch (Exception $e) {
                $apierror = $e->getMessage();
            }
            if (empty($apierror) && $session) {
                $response = [
                    'status' => 'success',
                    'redirecturl' => $session['url'], // Stripe Checkout URL.
                    'error' => [],
                ];
            } else {
                $response = [
                    'status' => 0,
                    'redirecturl' => null,
                    'error' => [
                        'message' => $apierror,
                    ],
                ];
            }
            return $response;
        }
    }
}
