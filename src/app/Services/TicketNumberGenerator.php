<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generates collision-free, gapless-per-commit, human-readable ticket numbers
 * (e.g. TKT-2026-000001) backed by a per-year counter row.
 *
 * Concurrency model:
 *  1. The year's counter row is created idempotently with INSERT IGNORE
 *     *before* the locking transaction. This avoids the classic
 *     "SELECT ... FOR UPDATE on a missing row" gap-lock race, in which two
 *     first-callers both see no row and then deadlock / collide on INSERT.
 *  2. Inside a transaction the row is read with SELECT ... FOR UPDATE, which
 *     serialises all concurrent generators for the same year on that row lock.
 *  3. The counter is incremented and the lock released on commit.
 *  Deadlocks/lock-wait errors are retried by the transaction helper.
 */
final class TicketNumberGenerator
{
    private const string TABLE = 'ticket_sequences';

    private const string FORMAT = 'TKT-%d-%06d';

    private const int MIN_YEAR = 1000;

    private const int MAX_YEAR = 9999;

    private const int TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function generate(?int $year = null): string
    {
        $year ??= (int) Date::now()->format('Y');

        $this->assertValidYear($year);

        $this->ensureSequenceRowExists($year);

        $sequence = $this->connection->transaction(
            fn (): int => $this->incrementSequence($year),
            self::TRANSACTION_ATTEMPTS,
        );

        return sprintf(self::FORMAT, $year, $sequence);
    }

    private function ensureSequenceRowExists(int $year): void
    {
        $this->connection->table(self::TABLE)->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'updated_at' => Date::now(),
        ]);
    }

    private function incrementSequence(int $year): int
    {
        $row = $this->connection->table(self::TABLE)
            ->where('year', $year)
            ->lockForUpdate()
            ->first(['last_number']);

        if ($row === null) {
            throw new RuntimeException(sprintf('Ticket sequence row for year %d could not be initialised.', $year));
        }

        $next = (int) $row->last_number + 1;

        $this->connection->table(self::TABLE)
            ->where('year', $year)
            ->update([
                'last_number' => $next,
                'updated_at' => Date::now(),
            ]);

        return $next;
    }

    private function assertValidYear(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException(
                sprintf('Year must be between %d and %d, %d given.', self::MIN_YEAR, self::MAX_YEAR, $year),
            );
        }
    }
}
