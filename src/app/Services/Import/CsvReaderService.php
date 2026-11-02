<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RuntimeException;

final class CsvReaderService
{
    /**
     * Stream rows from a CSV file lazily to guarantee O(1) memory usage.
     *
     * @return LazyCollection<int, array{row_number: int, data: array<string, mixed>}>
     */
    public function streamRows(string $filePath): LazyCollection
    {
        return LazyCollection::make(function () use ($filePath) {
            if (! file_exists($filePath) || ! is_readable($filePath)) {
                throw new InvalidArgumentException("CSV file [{$filePath}] does not exist or is not readable.");
            }

            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new RuntimeException("Failed to open CSV stream for [{$filePath}].");
            }

            try {
                // Read and normalize headers from the first non-empty line
                $rawHeaders = fgetcsv($handle);
                if ($rawHeaders === false || $rawHeaders === [null]) {
                    return;
                }

                $headers = $this->normalizeHeaders($rawHeaders);
                $headerCount = count($headers);
                $rowNumber = 1; // Header is row 1

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;

                    // Skip empty lines
                    if ($row === [null] || empty(array_filter($row, fn ($val) => $val !== null && trim((string) $val) !== ''))) {
                        continue;
                    }

                    // Pad or truncate row to match headers count
                    $rowCount = count($row);
                    if ($rowCount < $headerCount) {
                        $row = array_pad($row, $headerCount, null);
                    } elseif ($rowCount > $headerCount) {
                        $row = array_slice($row, 0, $headerCount);
                    }

                    $combined = [];
                    foreach ($headers as $index => $key) {
                        $val = $row[$index] ?? null;
                        $combined[$key] = is_string($val) ? trim($val) : $val;
                    }

                    yield [
                        'row_number' => $rowNumber,
                        'data' => $combined,
                    ];
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * Count data rows in the CSV file without loading entire content into memory.
     */
    public function countRows(string $filePath): int
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return 0;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        try {
            // Read header
            $header = fgetcsv($handle);
            if ($header === false || $header === [null]) {
                return 0;
            }

            while (($row = fgetcsv($handle)) !== false) {
                if ($row !== [null] && ! empty(array_filter($row, fn ($val) => $val !== null && trim((string) $val) !== ''))) {
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * Normalize header row values into clean, predictable keys.
     *
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    public function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $header = (string) ($header ?? '');

            // Strip UTF-8 BOM if present
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            $clean = strtolower(trim($header));

            // Normalize aliases
            $key = match ($clean) {
                'ticket #', 'ticket_no', 'ticket no', 'ticket number', 'ticket_id' => 'ticket_number',
                'customer', 'client_email', 'user_email' => 'customer_email',
                'agent_email', 'assigned_to', 'assigned_agent', 'agent' => 'assigned_agent_email',
                'cat', 'category_name' => 'category',
                default => preg_replace('/[^a-z0-9_]+/', '_', $clean) ?? $clean,
            };

            $key = trim($key, '_');
            $normalized[] = $key !== '' ? $key : "column_{$index}";
        }

        return $normalized;
    }
}
