<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_schooleescore_bridge',
        get_string('pluginname', 'local_schooleescore_bridge')));

    $settings = new admin_settingpage('local_schooleescore_bridge_settings',
        get_string('settings', 'local_schooleescore_bridge'));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/api_base_url',
        get_string('api_base_url', 'local_schooleescore_bridge'),
        get_string('api_base_url_desc', 'local_schooleescore_bridge'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/client_id',
        get_string('api_username', 'local_schooleescore_bridge'),
        get_string('api_username_desc', 'local_schooleescore_bridge'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_schooleescore_bridge/client_secret',
        get_string('api_password_or_token', 'local_schooleescore_bridge'),
        get_string('api_password_or_token_desc', 'local_schooleescore_bridge'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_schooleescore_bridge/webhook_secret',
        get_string('webhook_secret', 'local_schooleescore_bridge'),
        get_string('webhook_secret_desc', 'local_schooleescore_bridge'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/default_term_code',
        get_string('default_term_code', 'local_schooleescore_bridge'),
        get_string('default_term_code_desc', 'local_schooleescore_bridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/default_grade_period_id',
        get_string('default_grade_period_id', 'local_schooleescore_bridge'),
        get_string('default_grade_period_id_desc', 'local_schooleescore_bridge'),
        1,
        PARAM_INT
    ));

    // Legacy name for the same value, read only as a fallback so upgraded sites
    // that set it keep working. The dispatcher prefers default_grade_period_id.
    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/default_grade_category_id',
        get_string('default_grade_category_id', 'local_schooleescore_bridge'),
        get_string('default_grade_category_id_desc', 'local_schooleescore_bridge'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_schooleescore_bridge/enable_sso',
        get_string('enable_sso', 'local_schooleescore_bridge'),
        get_string('enable_sso_desc', 'local_schooleescore_bridge'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_schooleescore_bridge/enable_payment_gating',
        get_string('enable_payment_gating', 'local_schooleescore_bridge'),
        get_string('enable_payment_gating_desc', 'local_schooleescore_bridge'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_schooleescore_bridge/sync_enrollments_dry_run',
        get_string('sync_enrollments_dry_run', 'local_schooleescore_bridge'),
        get_string('sync_enrollments_dry_run_desc', 'local_schooleescore_bridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_schooleescore_bridge/suspend_unenrolled_students',
        get_string('suspend_unenrolled_students', 'local_schooleescore_bridge'),
        get_string('suspend_unenrolled_students_desc', 'local_schooleescore_bridge'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/log_retention_days',
        get_string('log_retention_days', 'local_schooleescore_bridge'),
        get_string('log_retention_days_desc', 'local_schooleescore_bridge'),
        90,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_schooleescore_bridge/heading_field_mapping',
        get_string('heading_field_mapping', 'local_schooleescore_bridge'),
        get_string('heading_field_mapping_desc', 'local_schooleescore_bridge')
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_external_id_path',
        get_string('map_user_external_id_path', 'local_schooleescore_bridge'),
        get_string('map_user_external_id_path_desc', 'local_schooleescore_bridge'),
        'id',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_username_path',
        get_string('map_user_username_path', 'local_schooleescore_bridge'),
        get_string('map_user_username_path_desc', 'local_schooleescore_bridge'),
        'id_number',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_idnumber_path',
        get_string('map_user_idnumber_path', 'local_schooleescore_bridge'),
        get_string('map_user_idnumber_path_desc', 'local_schooleescore_bridge'),
        'id_number',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_email_path',
        get_string('map_user_email_path', 'local_schooleescore_bridge'),
        get_string('map_user_email_path_desc', 'local_schooleescore_bridge'),
        'email_address|email',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_firstname_path',
        get_string('map_user_firstname_path', 'local_schooleescore_bridge'),
        get_string('map_user_firstname_path_desc', 'local_schooleescore_bridge'),
        'first_name|firstname',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_lastname_path',
        get_string('map_user_lastname_path', 'local_schooleescore_bridge'),
        get_string('map_user_lastname_path_desc', 'local_schooleescore_bridge'),
        'last_name|lastname',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_type_path',
        get_string('map_user_type_path', 'local_schooleescore_bridge'),
        get_string('map_user_type_path_desc', 'local_schooleescore_bridge'),
        'user_type|type',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_schooleescore_bridge/sync_profile_pictures',
        get_string('sync_profile_pictures', 'local_schooleescore_bridge'),
        get_string('sync_profile_pictures_desc', 'local_schooleescore_bridge'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_user_picture_url_path',
        get_string('map_user_picture_url_path', 'local_schooleescore_bridge'),
        get_string('map_user_picture_url_path_desc', 'local_schooleescore_bridge'),
        'profile_picture',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_enrollment_status_path',
        get_string('map_enrollment_status_path', 'local_schooleescore_bridge'),
        get_string('map_enrollment_status_path_desc', 'local_schooleescore_bridge'),
        'status',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_enrollment_active_value',
        get_string('map_enrollment_active_value', 'local_schooleescore_bridge'),
        get_string('map_enrollment_active_value_desc', 'local_schooleescore_bridge'),
        'enrolled',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/map_enrollment_student_key_path',
        get_string('map_enrollment_student_key_path', 'local_schooleescore_bridge'),
        get_string('map_enrollment_student_key_path_desc', 'local_schooleescore_bridge'),
        'student.id_number|student.id',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_schooleescore_bridge/heading_password_template',
        get_string('heading_password_template', 'local_schooleescore_bridge'),
        get_string('heading_password_template_desc', 'local_schooleescore_bridge')
    ));

    $settings->add(new admin_setting_configtext(
        'local_schooleescore_bridge/default_password_template',
        get_string('default_password_template', 'local_schooleescore_bridge'),
        get_string('default_password_template_desc', 'local_schooleescore_bridge'),
        '~!@Adsco{id_number}',
        PARAM_TEXT
    ));

    $ADMIN->add('local_schooleescore_bridge', $settings);

    $ADMIN->add('local_schooleescore_bridge', new admin_externalpage(
        'local_schooleescore_bridge_dashboard',
        get_string('bridge_dashboard', 'local_schooleescore_bridge'),
        new moodle_url('/local/schooleescore_bridge/index.php'),
        'local/schooleescore_bridge:manage'
    ));

    // mappings.php existed but was not registered anywhere, so the only way to
    // reach it was to type the URL.
    $ADMIN->add('local_schooleescore_bridge', new admin_externalpage(
        'local_schooleescore_bridge_mappings',
        get_string('mappings', 'local_schooleescore_bridge'),
        new moodle_url('/local/schooleescore_bridge/mappings.php'),
        'local/schooleescore_bridge:manage'
    ));

    $ADMIN->add('local_schooleescore_bridge', new admin_externalpage(
        'local_schooleescore_bridge_connection',
        get_string('connection_test', 'local_schooleescore_bridge'),
        new moodle_url('/local/schooleescore_bridge/connection.php'),
        'local/schooleescore_bridge:manage'
    ));

    $ADMIN->add('local_schooleescore_bridge', new admin_externalpage(
        'local_schooleescore_bridge_queue',
        get_string('queue_monitor', 'local_schooleescore_bridge'),
        new moodle_url('/local/schooleescore_bridge/queue.php'),
        'local/schooleescore_bridge:viewlogs'
    ));

    $ADMIN->add('local_schooleescore_bridge', new admin_externalpage(
        'local_schooleescore_bridge_history',
        get_string('sync_history', 'local_schooleescore_bridge'),
        new moodle_url('/local/schooleescore_bridge/logs.php'),
        'local/schooleescore_bridge:viewlogs'
    ));
}
