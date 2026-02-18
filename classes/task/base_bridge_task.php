<?php
namespace local_schooleescore_bridge\task;

use core\lock\lock;
use core\lock\lock_config;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class with lock helper for bridge tasks.
 */
abstract class base_bridge_task extends \core\task\scheduled_task {
    /** @var lock|null */
    protected $lock = null;

    /**
     * Acquire per-task lock.
     *
     * @return bool
     */
    protected function acquire_lock(): bool {
        $factory = lock_config::get_lock_factory('local_schooleescore_bridge');
        $key = static::class . ':run';
        $this->lock = $factory->get_lock($key, 1);
        return (bool)$this->lock;
    }

    /**
     * Release task lock.
     */
    protected function release_lock(): void {
        if ($this->lock) {
            $this->lock->release();
            $this->lock = null;
        }
    }
}
