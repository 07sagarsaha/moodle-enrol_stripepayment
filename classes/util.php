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
     * Get the current secret key
     *
     * @return string
     */
    public static function get_current_secret_key() {
        return self::get_core()->get_current_secret_key();
    }

    /**
     * Get the current publishable key
     *
     * @return string
     */
    public static function get_current_publishable_key() {
        return self::get_core()->get_current_publishable_key();
    }

    /**
     * Make Stripe API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $secretkey Stripe secret key
     * @return array
     * @throws \Exception
     */
    public static function stripe_api_request($method, $endpoint, $data, $secretkey) {
        return self::get_core()->stripe_api_request($method, $endpoint, $data, $secretkey);
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
        return self::get_core()->get_stripe_amount($cost, $currency, $reverse);
    }

    /**
     * Get minimum amount for currency
     *
     * @param string $currency
     * @return float
     */
    public static function minamount($currency) {
        return self::get_core()->minamount($currency);
    }
}
