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
use core\lang_string;
use core_user;
use Exception;
use stdClass;

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
     * Return Currency as per country code
     *
     * @param integer $currency the country code
     * @return Country currency sign
     */
    public static function show_currency_symbol($currency) {
        $currencies = [
            'aed' => 'AED', 'afn' => '&#1547;', 'all' => '&#76;&#101;&#107;',
            'amd' => 'AMD', 'ang' => '&#402;', 'aoa' => 'AOA', 'ars' => '&#36;',
            'aud' => '&#36;', 'awg' => '&#402;', 'azn' => '&#1084;&#1072;&#1085;',
            'bam' => '&#75;&#77;', 'bbd' => '&#36;', 'bdt' => 'BDT', 'bgn' => '&#1083;&#1074;',
            'bhd' => 'BHD', 'bif' => 'BIF', 'bmd' => '&#36;', 'bnd' => '&#36;',
            'bob' => '&#36;&#98;', 'brl' => '&#82;&#36;', 'bsd' => '&#36;', 'btn' => 'BTN',
            'bwp' => '&#80;', 'byr' => '&#112;&#46;', 'bzd' => '&#66;&#90;&#36;',
            'cad' => '&#36;', 'cdf' => 'CDF', 'chf' => '&#67;&#72;&#70;', 'clp' => '&#36;',
            'cny' => '&#165;', 'cop' => '&#36;', 'crc' => '&#8353;', 'cuc' => 'CUC', 'cup' => '&#8369;',
            'cve' => 'CVE', 'czk' => '&#75;&#269;', 'djf' => 'DJF', 'dkk' => '&#107;&#114;',
            'dop' => '&#82;&#68;&#36;', 'dzd' => 'DZD', 'egp' => '&#163;', 'ern' => 'ERN', 'etb' => 'ETB',
            'eur' => '&#8364;', 'fjd' => '&#36;', 'fkp' => '&#163;', 'gbp' => '&#163;', 'gel' => 'GEL',
            'ggp' => '&#163;', 'ghs' => '&#162;', 'gip' => '&#163;', 'gmd' => 'GMD', 'gnf' => 'GNF',
            'gtq' => '&#81;', 'gyd' => '&#36;', 'hkd' => '&#36;', 'hnl' => '&#76;', 'hrk' => '&#107;&#110;',
            'htg' => 'HTG', 'huf' => '&#70;&#116;', 'idr' => '&#82;&#112;', 'ils' => '&#8362;',
            'imp' => '&#163;', 'inr' => '&#8377;', 'iqd' => 'IQD', 'irr' => '&#65020;', 'isk' => '&#107;&#114;',
            'jep' => '&#163;', 'jmd' => '&#74;&#36;', 'jod' => 'JOD', 'jpy' => '&#165;',
            'kes' => 'KES', 'kgs' => '&#1083;&#1074;', 'khr' => '&#6107;', 'kmf' => 'KMF', 'kpw' => '&#8361;',
            'krw' => '&#8361;', 'kwd' => 'KWD', 'kyd' => '&#36;', 'kzt' => '&#1083;&#1074;',
            'lak' => '&#8365;', 'lbp' => '&#163;', 'lkr' => '&#8360;', 'lrd' => '&#36;', 'lsl' => 'LSL',
            'lyd' => 'LYD', 'mad' => 'MAD', 'mdl' => 'MDL', 'mga' => 'MGA', 'mkd' => '&#1076;&#1077;&#1085;',
            'mmk' => 'MMK', 'mnt' => '&#8366;', 'mop' => 'MOP', 'mro' => 'MRO', 'mur' => '&#8360;',
            'mvr' => 'MVR', 'mwk' => 'MWK', 'mxn' => '&#36;', 'myr' => '&#82;&#77;', 'mzn' => '&#77;&#84;',
            'nad' => '&#36;', 'ngn' => '&#8358;', 'nio' => '&#67;&#36;', 'nok' => '&#107;&#114;', 'npr' => '&#8360;',
            'nzd' => '&#36;', 'omr' => '&#65020;', 'pab' => '&#66;&#47;&#46;', 'pen' => '&#83;&#47;&#46;',
            'pgk' => 'PGK', 'php' => '&#8369;', 'pkr' => '&#8360;', 'pln' => '&#122;&#322;', 'prb' => 'PRB',
            'pyg' => '&#71;&#115;', 'qar' => '&#65020;', 'ron' => '&#108;&#101;&#105;', 'rsd' => '&#1044;&#1080;&#1085;&#46;',
            'rub' => '&#1088;&#1091;&#1073;', 'rwf' => 'RWF', 'sar' => '&#65020;', 'sbd' => '&#36;', 'scr' => '&#8360;',
            'sdg' => 'SDG', 'sek' => '&#107;&#114;', 'sgd' => '&#36;', 'shp' => '&#163;', 'sll' => 'SLL', 'sos' => '&#83;',
            'srd' => '&#36;', 'ssp' => 'SSP', 'std' => 'STD', 'syp' => '&#163;', 'szl' => 'SZL', 'thb' => '&#3647;', 'tjs' => 'TJS',
            'tmt' => 'TMT', 'tnd' => 'TND', 'top' => 'TOP', 'try' => '&#8378;', 'ttd' => '&#84;&#84;&#36;',
            'twd' => '&#78;&#84;&#36;',
            'tzs' => 'TZS', 'uah' => '&#8372;', 'ugx' => 'UGX', 'usd' => '&#36;', 'uyu' => '&#36;&#85;',
            'uzs' => '&#1083;&#1074;',
            'vef' => '&#66;&#115;', 'vnd' => '&#8363;', 'vuv' => 'VUV', 'wst' => 'WST', 'xaf' => 'XAF',
            'xcd' => '&#36;', 'xof' => 'XOF',
            'xpf' => 'XPF', 'yer' => '&#65020;', 'zar' => '&#82;', 'zmw' => 'ZMW',
        ];
        $symbol = (array_key_exists($currency, $currencies)) ? $currencies[$currency] : $currency;
        return $symbol;
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
     * Creates can stripepayament enrol.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    public static function can_stripepayment_enrol(stdClass $instance) {
        global $DB;
        if ($instance->customint3 > 0) {
            // Max enrol limit specified.
            $count = $DB->count_records('user_enrolments', ['enrolid' => $instance->id]);
            if ($count >= $instance->customint3) {
                // Bad luck, no more stripepayment enrolments here.
                return false;
            }
        }
        return true;
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
     * @param string $operation API operation type
     * @param array $data Request data
     * @param string|null $resourceid Resource ID for specific operations
     * @return array Response data
     * @throws Exception
     */
    public static function stripe_api_request($operation, $data, $resourceid = null) {
        $endpointinfo = static::get_stripe_endpoint($operation, $resourceid);
        $method = $endpointinfo['method'];
        $endpoint = $endpointinfo['endpoint'];
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
     * Returns a list of Stripe API routes used by this plugin.
     *
     * @return array<string, array{
     *     method: string,
     *     path: string,
     *     needs_id: bool,
     *     message?: string
     * }>
     */
    public static function routes() {
        return [
            'coupon_retrieve' => [
                'method'   => 'GET',
                'path'     => 'coupons/',
                'needs_id' => true,
                'message'  => 'Coupon ID is required for coupon retrieval',
            ],
            'coupon_list' => [
                'method'   => 'GET',
                'path'     => 'coupons',
                'needs_id' => false,
            ],
            'customer_retrieve' => [
                'method'   => 'GET',
                'path'     => 'customers/',
                'needs_id' => true,
                'message'  => 'Customer ID is required for customer retrieval',
            ],
            'customer_list' => [
                'method'   => 'GET',
                'path'     => 'customers',
                'needs_id' => false,
            ],
            'customer_create' => [
                'method'   => 'POST',
                'path'     => 'customers',
                'needs_id' => false,
            ],
            'checkout_session_create' => [
                'method'   => 'POST',
                'path'     => 'checkout/sessions',
                'needs_id' => false,
            ],
            'checkout_session_retrieve' => [
                'method'   => 'GET',
                'path'     => 'checkout/sessions/',
                'needs_id' => true,
                'message'  => 'Session ID is required for checkout session retrieval',
            ],
            'payment_intent_retrieve' => [
                'method'   => 'GET',
                'path'     => 'payment_intents/',
                'needs_id' => true,
                'message'  => 'Payment Intent ID is required for payment intent retrieval',
            ],
            'payment_method_list' => [
                'method'   => 'GET',
                'path'     => 'payment_methods',
                'needs_id' => false,
            ],
            'refund_create' => [
                'method'   => 'POST',
                'path'     => 'refunds',
                'needs_id' => false,
            ],
        ];
    }

    /**
     * Make a cURL request to Stripe API with operation-based logic
     *
     * @param string $operation API operation type
     * @param string|null $resourceid Resource ID for specific operations
     * @return array Response data
     * @throws Exception
     */
    public static function get_stripe_endpoint($operation, $resourceid = null) {
        // HTTP method must be the first element.
        $routes = static::routes();

        if (!isset($routes[$operation])) {
            throw new Exception('Unknown Stripe operation: ' . $operation);
        }

        $route = $routes[$operation];

        if ($route['needs_id'] && !$resourceid) {
            throw new Exception($route['message']);
        }

        return [
            'method'   => $route['method'],
            'endpoint' => $route['needs_id'] ? $route['path'] . $resourceid : $route['path'],
        ];
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
