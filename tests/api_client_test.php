<?php
namespace local_schooleescore_bridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for API response extraction helpers.
 */
final class api_client_test extends \advanced_testcase {
    /**
     * Direct data arrays should be returned as-is.
     */
    public function test_extract_rows_supports_direct_data_arrays(): void {
        $body = [
            'data' => [
                ['id' => 10],
                ['id' => 11],
            ],
        ];

        $rows = \local_schooleescore_bridge\local\api_client::extract_rows($body);

        $this->assertCount(2, $rows);
        $this->assertSame(10, $rows[0]['id']);
        $this->assertSame(11, $rows[1]['id']);
    }

    /**
     * Nested datatable-style payloads should also be supported.
     */
    public function test_extract_rows_supports_nested_data_payloads(): void {
        $body = [
            'data' => [
                'data' => [
                    ['id' => 21],
                ],
            ],
        ];

        $rows = \local_schooleescore_bridge\local\api_client::extract_rows($body);

        $this->assertCount(1, $rows);
        $this->assertSame(21, $rows[0]['id']);
    }
}
