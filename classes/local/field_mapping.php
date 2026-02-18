<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Field mapping helper for converting API payloads to Moodle user fields.
 *
 * Settings store "dot paths" like "student.id_number" to support nested payloads.
 */
class field_mapping {
    /**
     * Get a plugin config value with default.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function cfg(string $key, string $default = ''): string {
        $value = (string)get_config('local_schooleescore_bridge', $key);
        return $value !== '' ? $value : $default;
    }

    /**
     * Extract a value from an array using a dot path.
     *
     * Supports fallback paths separated by "|" (first non-empty wins).
     *
     * @param array $row
     * @param string $path
     * @return mixed|null
     */
    public static function get_by_path(array $row, string $path) {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $candidates = array_map('trim', explode('|', $path));
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $value = $row;
            foreach (explode('.', $candidate) as $part) {
                $part = trim($part);
                if ($part === '' || !is_array($value) || !array_key_exists($part, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$part];
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Render a password template using placeholders like {id_number}, {username}.
     *
     * @param string $template
     * @param array $vars
     * @return string
     */
    public static function render_template(string $template, array $vars): string {
        $out = $template;
        foreach ($vars as $key => $val) {
            $out = str_replace('{' . $key . '}', (string)$val, $out);
        }
        return $out;
    }
}

