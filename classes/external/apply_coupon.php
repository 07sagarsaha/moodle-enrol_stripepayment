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
 * External library for stripepayment
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


 namespace enrol_stripepayment\external;
 use core\exception\moodle_exception;
 use core_external\external_api;
 use core_external\external_function_parameters;
 use core_external\external_value;
 use core_external\external_single_structure;
 use enrol_stripepayment\util;

 /**
  * External apply coupon for stripepayment
  *
  * @package    enrol_stripepayment
  * @author     DualCube <admin@dualcube.com>
  * @copyright  2019 DualCube Team(https://dualcube.com)
  * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
  */
class apply_coupon extends external_api {
    /**
     * Parameter for couponsettings function
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'couponid' => new external_value(PARAM_RAW, 'The coupon id to operate on'),
                'instanceid' => new external_value(PARAM_RAW, 'Update instance id'),
            ]
        );
    }

    /**
     * return type of couponsettings functioin
     */
    public static function execute_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_RAW, 'status: true if success'),
                'couponname' => new external_value(PARAM_RAW, 'coupon name', VALUE_OPTIONAL),
                'coupontype' => new external_value(PARAM_RAW, 'coupon type: percent_off or amount_off', VALUE_OPTIONAL),
                'discountvalue' => new external_value(PARAM_RAW, 'discount value', VALUE_OPTIONAL),
                'currency' => new external_value(PARAM_RAW, 'currency code', VALUE_OPTIONAL),
                'discountamount' => new external_value(PARAM_RAW, 'discount amount', VALUE_OPTIONAL),
                'uistate' => new external_value(PARAM_RAW, 'UI state: paid|error', VALUE_OPTIONAL),
                'message' => new external_value(PARAM_RAW, 'provides message', VALUE_OPTIONAL),
                'showsections' => new external_single_structure(
                    [
                        'paidenrollment' => new external_value(PARAM_BOOL, 'show payment button'),
                        'discountsection' => new external_value(PARAM_BOOL, 'show discount section'),
                    ],
                    'sections to show/hide',
                    VALUE_OPTIONAL
                ),
            ]
        );
    }

    /**
     * function for couponsettings with validation
     * @param string $couponid
     * @param int $instanceid
     * @return array
     */
    public static function execute($couponid, $instanceid) {
        global $DB;

        // Enhanced input validation.
        if (empty($couponid) || trim($couponid) === '') {
            throw new moodle_exception('couponcodeempty', 'enrol_stripepayment');
        }

        if (!is_numeric($instanceid) || $instanceid <= 0) {
            throw new moodle_exception('invalidinstanceformat', 'enrol_stripepayment');
        }
        $plugininstance = $DB->get_record("enrol", ["id" => $instanceid, "status" => 0]);
        if (!$plugininstance) {
            throw new moodle_exception('enrollmentinstancenotfound', 'enrol_stripepayment');
        }

        // Validate Stripe configuration.
        if (empty(util::get_current_secret_key())) {
            throw new moodle_exception('stripeconfigurationincomplete', 'enrol_stripepayment');
        }

        $defaultcost = (float)util::get_core()->get_config('cost');
        $cost = (float)$plugininstance->cost > 0 ? (float)$plugininstance->cost : $defaultcost;
        $currency = $plugininstance->currency ?: 'USD';
        $cost = format_float($cost, 2, false);

        $couponname = '';
        $coupontype = '';
        $discountvalue = 0;
        $discountamount = 0;

        $coupon = util::stripe_api_request('coupon_retrieve', $couponid);

        // Enhanced coupon validation.
        if (!$coupon || (isset($coupon['valid']) && !$coupon['valid'])) {
            throw new moodle_exception('invalidcoupon', 'enrol_stripepayment');
        }

        // Check if coupon has expired.
        if (isset($coupon['redeem_by']) && $coupon['redeem_by'] < time()) {
            throw new moodle_exception('couponhasexpired', 'enrol_stripepayment');
        }

        // Check if coupon has usage limits.
        if (
            isset($coupon['max_redemptions']) && isset($coupon['times_redeemed'])
            && $coupon['times_redeemed'] >= $coupon['max_redemptions']
        ) {
            throw new moodle_exception('couponlimitexceeded', 'enrol_stripepayment');
        }

        $couponname = $coupon['name'] ?? $couponid;

        if (isset($coupon['percent_off'])) {
            $discountamount = $cost * ($coupon['percent_off'] / 100);
            $cost -= $discountamount;
            $coupontype = 'percent_off';
            $discountvalue = $coupon['percent_off'];
        } else if (isset($coupon['amount_off'])) {
            // Ensure currency matches.
            if (isset($coupon['currency']) && strtoupper($coupon['currency']) !== strtoupper($currency)) {
                throw new moodle_exception('couponcurrencymismatch', 'enrol_stripepayment');
            }
            $discountamount = $coupon['amount_off'] / 100;
            $cost -= $discountamount;
            $coupontype = 'amount_off';
            $discountvalue = $coupon['amount_off'] / 100;
        } else {
            throw new moodle_exception('invalidcoupontype', 'enrol_stripepayment');
        }

        // Ensure cost doesn't go negative.
        $cost = max(0, $cost);
        $cost = format_float($cost, 2, false);
        $discountamount = format_float($discountamount, 2, false);

        $minamount = util::minamount($currency);

        // Calculate UI state for display purposes only.
        $uistate = [
            'state' => 'paid',
            'errormessage' => '',
            'showsections' => [
                'paidenrollment' => true,
                'discountsection' => ($discountamount > 0),
            ],
        ];

        if ($cost > 0 && $cost < $minamount) {
            // Cost is above 0 but below minimum threshold - show error.
            $uistate['state'] = 'error';
            $uistate['errormessage'] = get_string('couponminimumerror', 'enrol_stripepayment', [
                'amount' => $currency . ' ' . number_format($cost, 2),
                'minimum' => $currency . ' ' . number_format($minamount, 2),
            ]);
            $uistate['showsections']['paidenrollment'] = false;
        }

        return [
            'status' => $cost,
            'couponname' => $couponname,
            'coupontype' => $coupontype,
            'discountvalue' => $discountvalue,
            'currency' => $currency,
            'discountamount' => $discountamount,
            'uistate' => $uistate['state'],
            'message' => $uistate['state'] === 'error' ? $uistate['errormessage'] : 'Coupon applied successfully.',
            'showsections' => $uistate['showsections'],
        ];
    }
}
