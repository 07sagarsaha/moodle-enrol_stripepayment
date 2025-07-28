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
 * Stripe enrolment plugin - ENHANCED WITH LIVE/TEST TOGGLE.
 *
 * This plugin allows you to set up paid courses with Live/Test mode toggle.
 *
 * @package    enrol_stripepayment
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2019 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/enrol/stripepayment/lib.php');
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_settings',
        '',
        get_string('pluginnamedesc', 'enrol_stripepayment')
    ));
    
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_mode_settings',
        get_string('stripemodesettings', 'enrol_stripepayment'),
        get_string('stripemodesettingsdesc', 'enrol_stripepayment')
    ));

    // Current Mode Toggle
    $modeoptions = [
        'test' => get_string('testmode', 'enrol_stripepayment', 'Test Mode'),
        'live' => get_string('livemode', 'enrol_stripepayment', 'Live Mode'),
    ];
    
    $currentmode = get_config('enrol_stripepayment', 'stripemode') ?: 'test';
    $modedescription = get_string('stripemodedesc', 'enrol_stripepayment');
    
    $settings->add(new admin_setting_configselect(
        'enrol_stripepayment/stripe_mode',
        get_string('stripemode', 'enrol_stripepayment'),
        $modedescription,
        'test',
        $modeoptions
    ));

    // Mode Status Display
    $modestatustext = ($currentmode === 'live') ? 
        '<span style="color: #d32f2f; font-weight: bold;">LIVE MODE - Real payments will be processed</span>' :
        '<span style="color: #388e3c; font-weight: bold;">TEST MODE - Safe for testing</span>';
    
    $settings->add(new admin_setting_description(
        'enrol_stripepayment/mode_status',
        get_string('currentmodestatus', 'enrol_stripepayment'),
        $modestatustext
    ));
    
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_test_keys',
        get_string('testapikeys', 'enrol_stripepayment'),
        get_string('testapikeysdesc', 'enrol_stripepayment')
    ));

    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/test_publishablekey',
        get_string('testpublishablekey', 'enrol_stripepayment'),
        get_string('testpublishablekeydesc', 'enrol_stripepayment'),
        '',
        PARAM_TEXT
    ));
    
    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/test_secretkey',
        get_string('testsecretkey', 'enrol_stripepayment'),
        get_string('testsecretkeydesc', 'enrol_stripepayment'),
        '',
        PARAM_TEXT
    ));
    
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_live_keys',
        get_string('liveapikeys', 'enrol_stripepayment'),
        get_string('liveapikeysdesc', 'enrol_stripepayment')
    ));

    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/live_publishablekey',
        get_string('livepublishablekey', 'enrol_stripepayment'),
        get_string('livepublishablekeydesc', 'enrol_stripepayment'),
        '',
        PARAM_TEXT
    ));
    
    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/live_secretkey',
        get_string('livesecretkey', 'enrol_stripepayment'),
        get_string('livesecretkeydesc', 'enrol_stripepayment'),
        '',
        PARAM_TEXT
    ));
    
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_legacy_keys',
        get_string('legacyapikeys', 'enrol_stripepayment'),
        get_string('legacyapikeysdesc', 'enrol_stripepayment')
    ));

    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/publishablekey',
        get_string('publishablekey', 'enrol_stripepayment') . ' (Legacy)',
        get_string('publishablekeydesc', 'enrol_stripepayment') . ' - Use Test/Live keys above instead.',
        '',
        PARAM_TEXT
    ));
    
    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/secretkey',
        get_string('secretkey', 'enrol_stripepayment') . ' (Legacy)',
        get_string('secretkeydesc', 'enrol_stripepayment') . ' - Use Test/Live keys above instead.',
        '',
        PARAM_TEXT
    ));
    
    // Auto-migrate legacy keys if new keys are empty
    $legacy_publishable = get_config('enrol_stripepayment', 'publishablekey');
    $legacy_secret = get_config('enrol_stripepayment', 'secretkey');
    $test_publishable = get_config('enrol_stripepayment', 'test_publishablekey');
    $test_secret = get_config('enrol_stripepayment', 'test_secretkey');
    
    if (!empty($legacy_publishable) && empty($test_publishable)) {
        // Auto-detect if legacy keys are test or live and migrate accordingly
        if (strpos($legacy_secret, 'sk_test_') === 0) {
            set_config('test_publishablekey', $legacy_publishable, 'enrol_stripepayment');
            set_config('test_secretkey', $legacy_secret, 'enrol_stripepayment');
            set_config('stripe_mode', 'test', 'enrol_stripepayment');
        } else if (strpos($legacy_secret, 'sk_live_') === 0) {
            set_config('live_publishablekey', $legacy_publishable, 'enrol_stripepayment');
            set_config('live_secretkey', $legacy_secret, 'enrol_stripepayment');
            set_config('stripe_mode', 'live', 'enrol_stripepayment');
        }
    }
    
    $settings->add(new admin_setting_configcheckbox(
        'enrol_stripepayment/mailstudents',
        get_string('mailstudents', 'enrol_stripepayment'),
        '',
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'enrol_stripepayment/mailteachers',
        get_string('mailteachers', 'enrol_stripepayment'),
        '',
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'enrol_stripepayment/mailadmins',
        get_string('mailadmins', 'enrol_stripepayment'),
        '',
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'enrol_stripepayment/enablecouponsection',
        get_string('enablecouponsection', 'enrol_stripepayment'),
        '',
        0,
    ));

    // Variable $enroll button color.
    $settings->add( new admin_setting_configcolourpicker(
        'enrol_stripepayment/enrolbtncolor',
        get_string('enrolbtncolor', 'enrol_stripepayment'),
        get_string('enrolbtncolordes', 'enrol_stripepayment'),
        '#1177d1'
    ));
    // Note: let's reuse the ext sync constants and strings here, internally it is very similar,
    // it describes what should happen when users are not supposed to be enrolled any more.
    $options = [
        ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'),
        ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
    ];
    $settings->add(new admin_setting_configselect(
        'enrol_stripepayment/expiredaction',
        get_string('expiredaction', 'enrol_stripepayment'),
        get_string('expiredactionhelp', 'enrol_stripepayment'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES,
        $options
    ));

    // Webservice token.
    $webservicesoverview = $CFG->wwwroot . '/admin/search.php?query=enablewebservices';
    $restweblink = $CFG->wwwroot . '/admin/settings.php?section=webserviceprotocols';
    $createtoken = $CFG->wwwroot . '/admin/webservice/tokens.php';
    $settings->add(new admin_enrol_stripepayment_configtext(
        'enrol_stripepayment/webservice_token',
        get_string('webservicetokenstring', 'enrol_stripepayment'),
        get_string('enablewebservicesfirst', 'enrol_stripepayment') . '<a href="' . $webservicesoverview . '" target="_blank"> '
        . get_string('fromhere', 'enrol_stripepayment') . '</a> . '
        . get_string('create_user_token', 'enrol_stripepayment') . '<a href="' . $restweblink . '" target="_blank"> '
        . get_string('fromhere', 'enrol_stripepayment') . '</a> . '
        . get_string('enabledrestprotocol', 'enrol_stripepayment') . '<a href="' . $createtoken . '" target="_blank"> '
        . get_string('fromhere', 'enrol_stripepayment') . '</a>
        ',
        ''
    ));
    // Enrol instance defaults.
    $settings->add(new admin_setting_heading(
        'enrol_stripepayment_defaults',
        get_string('enrolinstancedefaults', 'admin'),
        get_string('enrolinstancedefaults_desc', 'admin')
    ));
    $options = [
        ENROL_INSTANCE_ENABLED  => get_string('yes'),
        ENROL_INSTANCE_DISABLED => get_string('no'),
    ];
    $settings->add(new admin_setting_configselect(
        'enrol_stripepayment/status',
        get_string('status', 'enrol_stripepayment'),
        get_string('status_desc', 'enrol_stripepayment'),
        ENROL_INSTANCE_DISABLED,
        $options
    ));
    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/cost',
        get_string('cost', 'enrol_stripepayment'),
        '',
        0,
        PARAM_FLOAT,
        4
    ));
    $stripecurrencies = enrol_get_plugin('stripepayment')->get_currencies();
    $settings->add(new admin_setting_configselect(
        'enrol_stripepayment/currency',
        get_string('currency', 'enrol_stripepayment'),
        '',
        'USD',
        $stripecurrencies
    ));
    $settings->add(new admin_setting_configtext(
        'enrol_stripepayment/maxenrolled',
        get_string('maxenrolled', 'enrol_stripepayment'),
        get_string('maxenrolledhelp', 'enrol_stripepayment'),
        0,
        PARAM_INT
    ));
    if (!during_initial_install()) {
        $options = get_default_enrol_roles(context_system::instance());
        $student = get_archetype_roles('student');
        $student = reset($student);
        $settings->add(new admin_setting_configselect(
            'enrol_stripepayment/roleid',
            get_string('defaultrole', 'enrol_stripepayment'),
            get_string('defaultrole_desc', 'enrol_stripepayment'),
            $student->id,
            $options
        ));
    }
    $settings->add(new admin_setting_configduration(
        'enrol_stripepayment/enrolperiod',
        get_string('enrolperiod', 'enrol_stripepayment'),
        get_string('enrolperioddesc', 'enrol_stripepayment'),
        0
    ));
}