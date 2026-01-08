<?php

namespace App\Validation;

/**
 * CSV Validator
 *
 * Validates CSV file structure and content before import
 */
class CsvValidator
{
    private const ALLOWED_ENCODINGS = ['UTF-8', 'ISO-8859-1', 'UTF-16'];

    /**
     * Validate CSV file structure
     *
     * @param string $path
     * @param array $requiredColumns
     * @return array
     */
    public static function validate(string $path, array $requiredColumns = []): array
    {
        $errors = [];

        if (!file_exists($path)) {
            return ['valid' => false, 'errors' => ['File not found']];
        }

        if (!is_readable($path)) {
            return ['valid' => false, 'errors' => ['File is not readable']];
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['valid' => false, 'errors' => ['Cannot open file']];
        }

        // Read header row
        $header = fgetcsv($handle);
        fclose($handle);

        if (!$header) {
            $errors[] = 'CSV file is empty or header row cannot be read';
        } elseif (!empty($requiredColumns)) {
            $missing = array_diff($requiredColumns, $header);
            if (!empty($missing)) {
                $errors[] = 'Missing required columns: ' . implode(', ', $missing);
                $errors[] = 'Found columns: ' . implode(', ', $header);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'header' => $header ?? [],
        ];
    }

    /**
     * Validate file size (prevent memory exhaustion)
     *
     * @param string $path
     * @param int $maxBytes
     * @return array
     */
    public static function validateSize(string $path, int $maxBytes = 104857600): array // 100MB default
    {
        $size = filesize($path);

        if ($size === false) {
            return ['valid' => false, 'error' => 'Cannot determine file size'];
        }

        if ($size > $maxBytes) {
            return [
                'valid' => false,
                'error' => 'File too large. Maximum size: ' . self::formatBytes($maxBytes) .
                    ', Actual size: ' . self::formatBytes($size),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Format bytes to human-readable format
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
