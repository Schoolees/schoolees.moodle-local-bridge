<?php
namespace local_schooleescore_bridge;

use local_schooleescore_bridge\local\field_mapping;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the dot-path field mapping helper.
 */
final class field_mapping_test extends \advanced_testcase {
    /**
     * Nested paths resolve through the payload.
     */
    public function test_get_by_path_walks_nested_keys(): void {
        $row = ['student' => ['id_number' => '2026-0001', 'id' => 42]];

        $this->assertSame('2026-0001', field_mapping::get_by_path($row, 'student.id_number'));
        $this->assertSame(42, field_mapping::get_by_path($row, 'student.id'));
    }

    /**
     * The first non-empty fallback wins.
     */
    public function test_get_by_path_uses_first_non_empty_fallback(): void {
        $row = ['email_address' => '', 'email' => 'a@example.com'];

        $this->assertSame('a@example.com', field_mapping::get_by_path($row, 'email_address|email'));
    }

    /**
     * A path that does not resolve yields null rather than a partial value.
     */
    public function test_get_by_path_returns_null_for_missing_paths(): void {
        $row = ['student' => ['id' => 1]];

        $this->assertNull(field_mapping::get_by_path($row, 'student.id_number'));
        $this->assertNull(field_mapping::get_by_path($row, ''));
        $this->assertNull(field_mapping::get_by_path($row, 'student.id.deeper'));
    }

    /**
     * Templates substitute every placeholder they are given.
     */
    public function test_render_template_substitutes_placeholders(): void {
        $rendered = field_mapping::render_template('~!@Adsco{id_number}', ['id_number' => '2026-0001']);

        $this->assertSame('~!@Adsco2026-0001', $rendered);
    }

    /**
     * Unknown placeholders are left alone rather than blanked.
     */
    public function test_render_template_leaves_unknown_placeholders(): void {
        $rendered = field_mapping::render_template('{username}-{unknown}', ['username' => 'abc']);

        $this->assertSame('abc-{unknown}', $rendered);
    }

    /**
     * cfg() falls back to the supplied default when the setting is empty.
     */
    public function test_cfg_falls_back_to_default(): void {
        $this->resetAfterTest();

        $this->assertSame('enrolled', field_mapping::cfg('map_enrollment_active_value', 'enrolled'));

        set_config('map_enrollment_active_value', 'ongoing', 'local_schooleescore_bridge');
        $this->assertSame('ongoing', field_mapping::cfg('map_enrollment_active_value', 'enrolled'));
    }
}
