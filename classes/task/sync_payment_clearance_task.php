<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync payment and clearance states.
 */
class sync_payment_clearance_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_payment_clearance', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        global $DB;

        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: sync_payment_clearance_task is already running.');
            return;
        }

        try {
            sync_log_service::log([
                'job_name' => 'sync_payment_clearance',
                'entity_type' => 'payment',
                'direction' => 'pull',
                'result' => 'partial',
                'error_message' => 'Payment clearance endpoint is not available in current SchooleesCore API.',
            ]);
        } finally {
            $this->release_lock();
        }
    }
}
