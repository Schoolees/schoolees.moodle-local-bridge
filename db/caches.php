<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Cache definitions for local_schooleescore_bridge.
 *
 * @package    local_schooleescore_bridge
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Rolling per-endpoint success/failure window for the circuit breaker.
    // This used to live in plugin config, which meant every single outbound
    // request wrote a config row and purged Moodle's site-wide config cache.
    'circuitbreaker' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 8,
    ],
];
