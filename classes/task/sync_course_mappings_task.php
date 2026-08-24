<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\mapping_service;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Rebuild course mappings from the identifiers SchooleesCore's Moodle export writes.
 */
class sync_course_mappings_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_course_mappings', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: sync_course_mappings_task is already running.');
            return;
        }

        try {
            $result = mapping_service::autodiscover();

            sync_log_service::log([
                'job_name' => 'sync_course_mappings',
                'entity_type' => 'course',
                'direction' => 'pull',
                'http_status' => $result['error'] === '' ? 200 : null,
                'result' => $result['error'] === '' ? 'success' : 'failure',
                'error_message' => $result['error'],
                'response_json' => $result,
            ]);

            mtrace('local_schooleescore_bridge: course mappings matched=' . $result['matched']
                . ' unmatched=' . $result['unmatched'] . ' skipped=' . $result['skipped']);
        } finally {
            $this->release_lock();
        }
    }
}
