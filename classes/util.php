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

use Exception;

/**
 * Utility class for Stripe payment plugin
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /** @var enrol_stripepayment_plugin|null Cached plugin instance */
    private static $plugin = null;

    /**
     * Get the stripepayment plugin instance
     *
     * @return \enrol_stripepayment_plugin
     */
    public static function get_core() {
        if (self::$plugin === null) {
            self::$plugin = enrol_get_plugin('stripepayment');
        }
        return self::$plugin;
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
     * Stripe API base URL
     */
    public const STRIPE_API_BASE = 'https://api.stripe.com/v1/';

    /**
     * Make a cURL request to Stripe API
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $secretkey Stripe secret key
     * @return array Response data
     * @throws Exception
     */
    public static function stripe_api_request($method, $endpoint, $data, $secretkey) {
        $url = self::STRIPE_API_BASE . $endpoint;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $secretkey . ':');
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
}
