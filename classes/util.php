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
 * Utility class for Stripe payment plugin
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_stripepayment;

use context_course;
use context_system;
use core_user;
use Exception;
use lang_string;

/**
 * Utility class for Stripe payment plugin
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Get the enrol_stripepayment plugin instance.
     *
     * @return \enrol_stripepayment_plugin
     */
    public static function get_core() {
        return enrol_get_plugin('stripepayment');
    }

    /**
     * Lists all currencies available for plugin.
     * @return $currencies
     */
    public static function get_currencies() {
        // See https://www.stripe.com/cgi-bin/webscr?cmd=p/sell/mc/mc_intro-outside,
        // 3-character ISO-4217: https://cms.stripe.com/us/cgi-bin/?cmd=
        // _render-content&content_ID=developer/e_howto_api_currency_codes.
        $codes = [
            'USD', 'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD',
            'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK',
            'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK',
            'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP',
            'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN',
            'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB',
            'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD',
            'TWD', 'TZS', 'UAH', 'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR',
        ];
        $currencies = [];
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }
        return $currencies;
    }

    /**
     * Get the current Stripe mode (test or live) - NEW METHOD.
     *
     * @return string 'test' or 'live'
     */
    public static function get_stripe_mode() {
        $mode = get_config('enrol_stripepayment', 'stripemode');
        return $mode ?: 'test'; // Default to test mode for safety.
    }

    /**
     * Get the appropriate API keys based on current mode - NEW METHOD.
     *
     * @return array Array with 'publishable', 'secret', and 'mode' keys
     */
    public static function get_current_api_keys() {
        $mode = self::get_stripe_mode();

        if ($mode === 'live') {
            $publishable = get_config('enrol_stripepayment', 'livepublishablekey');
            $secret = get_config('enrol_stripepayment', 'livesecretkey');
        } else {
            $publishable = get_config('enrol_stripepayment', 'testpublishablekey');
            $secret = get_config('enrol_stripepayment', 'testsecretkey');
        }

        return [
            'publishable' => $publishable,
            'secret' => $secret,
            'mode' => $mode,
        ];
    }

    /**
     * Get the current secret key based on mode - NEW METHOD.
     *
     * @return string The appropriate secret key
     */
    public static function get_current_secret_key() {
        $keys = self::get_current_api_keys();
        return $keys['secret'];
    }

    /**
     * Get the current publishable key based on mode.
     *
     * @return string The appropriate publishable key
     */
    public static function get_current_publishable_key() {
        $keys = self::get_current_api_keys();
        return $keys['publishable'];
    }

    /**
     * Validate API keys for the current mode.
     *
     * @return array Array with 'valid' boolean and 'errors' array
     */
    public static function validate_current_api_keys() {
        $keys = self::get_current_api_keys();
        $errors = [];

        if (empty($keys['secret'])) {
            $errors[] = 'Secret key is missing for ' . $keys['mode'] . ' mode';
        }

        if (empty($keys['publishable'])) {
            $errors[] = 'Publishable key is missing for ' . $keys['mode'] . ' mode';
        }

        // Validate key format.
        if (!empty($keys['secret'])) {
            $expectedprefix = $keys['mode'] === 'live' ? 'sk_live_' : 'sk_test_';
            if (strpos($keys['secret'], $expectedprefix) !== 0) {
                $errors[] = 'Secret key format is incorrect for ' . $keys['mode'] . ' mode';
            }
        }

        if (!empty($keys['publishable'])) {
            $expectedprefix = $keys['mode'] === 'live' ? 'pk_live_' : 'pk_test_';
            if (strpos($keys['publishable'], $expectedprefix) !== 0) {
                $errors[] = 'Publishable key format is incorrect for ' . $keys['mode'] . ' mode';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get mode status display text - NEW METHOD.
     *
     * @return string HTML formatted status text
     */
    public static function get_mode_status_display() {
        $mode = self::get_stripe_mode();
        $validation = self::validate_current_api_keys();

        if (!$validation['valid']) {
            return '<span style="color: #d32f2f; font-weight: bold;">⚠️ '
            . strtoupper($mode) . ' MODE - Configuration Error</span>';
        }

        if ($mode === 'live') {
            return '<span style="color: #d32f2f; font-weight: bold;">🔴 LIVE MODE - Real payments will be processed</span>';
        } else {
            return '<span style="color: #388e3c; font-weight: bold;">🟢 TEST MODE - Safe for testing</span>';
        }
    }

    /**
     * Make a cURL request to Stripe API with operation-based logic
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $operation API operation type
     * @param array $data Request data
     * @param string|null $resourceid Resource ID for specific operations
     * @return array Response data
     * @throws Exception
     */
    public static function stripe_api_request($method, $operation, $data, $resourceid = null) {
        $endpoint = static::get_stripe_endpoint($operation, $resourceid);
        $url = 'https://api.stripe.com/v1/' . $endpoint;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, self::get_current_secret_key() . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else if ($method === 'GET') {
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
            }
        } else if ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($curlerror) {
            throw new Exception('cURL error: ' . $curlerror);
        }

        $decoded = json_decode($response, true);

        if ($httpcode >= 400) {
            $error = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error';
            throw new Exception('Stripe API error: ' . $error . ' (HTTP ' . $httpcode . ')');
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Stripe');
        }

        return $decoded;
    }

    /**
     * Get Stripe API endpoint based on operation type
     *
     * @param string $operation The operation type
     * @param string|null $resourceid Resource ID for specific operations
     * @return string The Stripe API endpoint
     * @throws Exception
     */
    public static function get_stripe_endpoint($operation, $resourceid = null) {
        switch ($operation) {
            case 'coupon_retrieve':
                if (!$resourceid) {
                    throw new Exception('Coupon ID is required for coupon retrieval');
                }
                return 'coupons/' . $resourceid;

            case 'coupon_list':
                return 'coupons';

            case 'customer_retrieve':
                if (!$resourceid) {
                    throw new Exception('Customer ID is required for customer retrieval');
                }
                return 'customers/' . $resourceid;

            case 'customer_list':
                return 'customers';

            case 'customer_create':
                return 'customers';

            case 'checkout_session_create':
                return 'checkout/sessions';

            case 'checkout_session_retrieve':
                if (!$resourceid) {
                    throw new Exception('Session ID is required for checkout session retrieval');
                }
                return 'checkout/sessions/' . $resourceid;

            case 'payment_intent_retrieve':
                if (!$resourceid) {
                    throw new Exception('Payment Intent ID is required for payment intent retrieval');
                }
                return 'payment_intents/' . $resourceid;

            case 'payment_method_list':
                return 'payment_methods';

            case 'refund_create':
                return 'refunds';

            default:
                throw new Exception('Unknown Stripe operation: ' . $operation);
        }
    }

    /**
     * Get stripe amount
     *
     * @param float $cost
     * @param string $currency
     * @param bool $reverse
     * @return float
     */
    public static function get_stripe_amount($cost, $currency, $reverse = false) {
        $nodecimalcurrencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
            'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ];
        if (!$currency) {
            $currency = 'USD';
        }
        if (in_array(strtolower($currency), $nodecimalcurrencies)) {
            return abs($cost);
        } else {
            if ($reverse) {
                return abs((float) $cost / 100);
            } else {
                return abs((float) $cost * 100);
            }
        }
    }

    /**
     * Get minimum amount for currency
     *
     * @param string $currency
     * @return float
     */
    public static function minamount($currency) {
        $minamount = [
            'USD' => 0.5, 'AED' => 2.0, 'AUD' => 0.5, 'BGN' => 1.0, 'BRL' => 0.5,
            'CAD' => 0.5, 'CHF' => 0.5, 'CZK' => 15.0, 'DKK' => 2.5, 'EUR' => 0.5,
            'GBP' => 0.3, 'HKD' => 4.0, 'HUF' => 175.0, 'INR' => 0.5, 'JPY' => 50,
            'MXN' => 10, 'MYR' => 2, 'NOK' => 3.0, 'NZD' => 0.5, 'PLN' => 2.0,
            'RON' => 2.0, 'SEK' => 3.0, 'SGD' => 0.5, 'THB' => 10,
        ];
        $minamount = $minamount[$currency] ?? 0.5;
        return $minamount;
    }

    /**
     * validate plugininstance, course, user, context if validate then ok
     * @param number $userid
     * @param number $instanceid
     * @return array
     * else send message to admin
     */
    public static function validate_data($userid, $instanceid) {
        global $DB, $CFG;

        // Validate enrolment instance.
        if (!$plugininstance = $DB->get_record("enrol", ["id" => $instanceid, "status" => 0])) {
            self::message_stripepayment_error_to_admin(
                get_string('invalidinstance', 'enrol_stripepayment'),
                ["id" => $plugininstance->courseid]
            );
            redirect($CFG->wwwroot);
        }

        // Validate course.
        if (!$course = $DB->get_record("course", ["id" => $plugininstance->courseid])) {
            self::message_stripepayment_error_to_admin(
                get_string('invalidcourseid', 'enrol_stripepayment'),
                ["id" => $plugininstance->courseid]
            );
            redirect($CFG->wwwroot);
        }

        // Validate context.
        if (!$context = context_course::instance($course->id, IGNORE_MISSING)) {
            self::message_stripepayment_error_to_admin(
                get_string('invalidcontextid', 'enrol_stripepayment'),
                ["id" => $course->id]
            );
            redirect($CFG->wwwroot);
        }

        // Validate user.
        if (!$user = $DB->get_record("user", ["id" => $userid])) {
            self::message_stripepayment_error_to_admin("Not orderdetails valid user id", ["id" => $userid]);
            redirect($CFG->wwwroot . '/course/view.php?id=' . $course->id);
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
            $messagebody .= s($key) . " => " . s($value) . "\n";
        }
        $messagesubject = "STRIPE PAYMENT ERROR: " . $subject;
        $fullmessage = $messagebody;
        $fullmessagehtml = '<p>' . nl2br(s($messagebody)) . '</p>';

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
        $message->contexturl = new \core\url('/admin/index.php');
        $message->contexturlname = 'Site administration';

        $messageid = message_send($message);
        if (!$messageid) {
            debugging('Failed to send stripepayment error notification to admin: ' . $admin->id, DEBUG_DEVELOPER);
        }
    }

    /**
     * Send message to user
     *
     * @param stdClass $course Course object
     * @param stdClass $userfrom User sending the message
     * @param mixed $userto User(s) receiving the message
     * @param string $subject Message subject
     * @param stdClass $orderdetails Order details
     * @param string $shortname Course shortname
     * @param string $fullmessage Full message text
     * @param string $fullmessagehtml Full message HTML
     * @return void
     */
    public static function send_message(
        $course,
        $userfrom,
        $userto,
        $subject,
        $orderdetails,
        $shortname,
        $fullmessage,
        $fullmessagehtml
    ) {
        $recipients = is_array($userto) ? $userto : [$userto];
        foreach ($recipients as $recipient) {
            $message = new \core\message\message();
            $message->courseid = $course->id;
            $message->component = 'enrol_stripepayment';
            $message->name = 'stripepayment_enrolment';
            $message->userfrom = $userfrom;
            $message->userto = $recipient;
            $message->subject = $subject;
            $message->fullmessage = $fullmessage;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $fullmessagehtml;
            $message->smallmessage = get_string('newenrolment', 'enrol_stripepayment', $shortname);
            $message->notification = 1;
            $message->contexturl = new \core\url('/course/view.php', ['id' => $course->id]);
            $message->contexturlname = $orderdetails->coursename;

            if (!message_send($message)) {
                debugging("Failed to send stripepayment enrolment notification to user: {$recipient->id}", DEBUG_DEVELOPER);
            }
        }
    }

}
