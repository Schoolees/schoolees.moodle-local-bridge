<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync payment and clearance states.
 *
 * SchooleesCore exposes no clearance feed yet, so this task cannot populate
 * local_ses_payment_cache. It stays registered (disabled by default) so the
 * schedule is already in place when the endpoint lands.
 */
class sync_payment_clearance_task extends base_bridge_task {
    /** @var string Config key holding the epoch of the last "not implemented" notice. */
    private const NOTICE_KEY = 'payment_clearance_notice_at';

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
        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: sync_payment_clearance_task is already running.');
            return;
        }

        try {
            // Nothing to do, and nothing worth saying, unless the site is actually
            // gating on clearance. This used to write a failure row every 30
            // minutes, which buried real failures in the sync history.
            if (!get_config('local_schooleescore_bridge', 'enable_payment_gating')) {
                mtrace('local_schooleescore_bridge: payment gating is off, nothing to sync.');
                return;
            }

            $lastnotice = (int)get_config('local_schooleescore_bridge', self::NOTICE_KEY);
            if ($lastnotice > (time() - DAYSECS)) {
                return;
            }
            set_config(self::NOTICE_KEY, (string)time(), 'local_schooleescore_bridge');

            sync_log_service::log([
                'job_name' => 'sync_payment_clearance',
                'entity_type' => 'payment',
                'direction' => 'pull',
                'result' => 'partial',
                'error_message' => 'Payment gating is enabled but SchooleesCore exposes no clearance endpoint, '
                    . 'so no user can be gated. Disable payment gating until the endpoint exists.',
            ]);
        } finally {
            $this->release_lock();
        }
    }
}
