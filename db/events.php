<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\grade_updated',
        'callback' => '\\local_schooleescore_bridge\\local\\observers::grade_updated',
        'internal' => false,
        'priority' => 9999,
    ],
];
