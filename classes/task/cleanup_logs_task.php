<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup old sync logs.
 */
class cleanup_logs_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_logs', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: cleanup_logs_task is already running.');
            return;
        }

        try {
            $retentiondays = (int)get_config('local_schooleescore_bridge', 'log_retention_days');
            if ($retentiondays <= 0) {
                $retentiondays = 90;
            }
            $olderthan = time() - ($retentiondays * DAYSECS);
            sync_log_service::purge_older_than($olderthan);
        } finally {
            $this->release_lock();
        }
    }
}
