<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Core has no \core\event\grade_updated; the event raised whenever a
        // grade_grade row changes is user_graded. Subscribing to a class that
        // does not exist is silent, so the queue simply never filled.
        'eventname' => '\\core\\event\\user_graded',
        'callback' => '\\local_schooleescore_bridge\\local\\observers::grade_updated',
        'internal' => false,
        'priority' => 9999,
    ],
];
