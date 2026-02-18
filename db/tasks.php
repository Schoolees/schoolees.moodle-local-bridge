<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_schooleescore_bridge\\task\\migrate_identity_keys_task',
        'blocking' => 0,
        'minute' => '7',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => '\\local_schooleescore_bridge\\task\\sync_users_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => '\\local_schooleescore_bridge\\task\\sync_enrollments_task',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => '\\local_schooleescore_bridge\\task\\dispatch_grade_queue_task',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => '\\local_schooleescore_bridge\\task\\sync_payment_clearance_task',
        'blocking' => 0,
        'minute' => '*/30',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => '\\local_schooleescore_bridge\\task\\cleanup_logs_task',
        'blocking' => 0,
        'minute' => '17',
        'hour' => '2',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
