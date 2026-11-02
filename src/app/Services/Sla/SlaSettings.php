<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Validated, immutable snapshot of the SLA policy (config/sla.php).
 *
 * All invariants are enforced at construction so the calculator can rely on
 * a well-formed calendar (non-empty work week, start < end, valid dates).
 */
final readonly class SlaSettings
{
    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /** @var list<int> */
    public array $businessDays;

    /** @var array<string, int> */
    public array $priorityDurations;

    /** @var array<string, true> */
    private array $holidayLookup;

    /**
     * @param  list<int>  $businessDays  Carbon day-of-week constants (0 = Sunday … 6 = Saturday).
     * @param  array<string, int>  $priorityDurations  Business hours keyed by TicketPriority value.
     * @param  list<string>  $holidays  "Y-m-d" dates in the SLA timezone.
     */
    public function __construct(
        public string $timezone,
        array $businessDays = [
            CarbonInterface::SUNDAY,
            CarbonInterface::MONDAY,
            CarbonInterface::TUESDAY,
            CarbonInterface::WEDNESDAY,
            CarbonInterface::THURSDAY,
        ],
        public string $businessHoursStart = '09:00',
        public string $businessHoursEnd = '18:00',
        array $priorityDurations = [],
        public float $warningThreshold = 0.80,
        array $holidays = [],
    ) {
        self::assertTimezone($timezone);
        self::assertTime($businessHoursStart, 'start');
        self::assertTime($businessHoursEnd, 'end');

        if ($businessHoursEnd <= $businessHoursStart) {
            throw new InvalidArgumentException(sprintf(
                'SLA business hours end (%s) must be after start (%s).',
                $businessHoursEnd,
                $businessHoursStart,
            ));
        }

        self::assertThreshold($warningThreshold);

        $this->businessDays = self::normaliseBusinessDays($businessDays);
        $this->priorityDurations = self::normalisePriorityDurations($priorityDurations);
        $this->holidayLookup = self::normaliseHolidays($holidays);
    }

    /**
     * @param  array<string, mixed>  $config  The config('sla') array.
     */
    public static function fromConfig(array $config, string $fallbackTimezone = 'UTC'): self
    {
        $timezone = $config['timezone'] ?? null;
        $hours = (array) ($config['business_hours'] ?? []);

        return new self(
            timezone: is_string($timezone) && $timezone !== '' ? $timezone : $fallbackTimezone,
            businessDays: array_map('intval', (array) ($config['business_days'] ?? [])),
            businessHoursStart: (string) ($hours['start'] ?? '09:00'),
            businessHoursEnd: (string) ($hours['end'] ?? '18:00'),
            priorityDurations: array_map('intval', (array) ($config['priority_durations'] ?? [])),
            warningThreshold: (float) ($config['warning_threshold_percentage'] ?? 0.80),
            holidays: array_map('strval', array_values((array) ($config['holidays'] ?? []))),
        );
    }

    public static function assertThreshold(float $threshold): void
    {
        if ($threshold <= 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException(sprintf(
                'SLA warning threshold must be a fraction in (0, 1], %s given.',
                $threshold,
            ));
        }
    }

    public function isBusinessWeekday(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->businessDays, true);
    }

    /**
     * $date must already be expressed in the SLA timezone.
     */
    public function isHoliday(CarbonInterface $date): bool
    {
        return isset($this->holidayLookup[$date->format('Y-m-d')]);
    }

    /**
     * @return list<string>
     */
    public function holidays(): array
    {
        return array_keys($this->holidayLookup);
    }

    public function businessMinutesPerDay(): int
    {
        return self::toMinutes($this->businessHoursEnd) - self::toMinutes($this->businessHoursStart);
    }

    /**
     * Configured business hours for a priority, falling back to the enum's default policy.
     */
    public function hoursFor(TicketPriority $priority): int
    {
        return $this->priorityDurations[$priority->value] ?? $priority->slaHours();
    }

    private static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private static function assertTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Invalid SLA timezone "%s".', $timezone), 0, $e);
        }
    }

    private static function assertTime(string $time, string $label): void
    {
        if (preg_match(self::TIME_PATTERN, $time) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'SLA business hours %s must be in 24h "HH:MM" format, "%s" given.',
                $label,
                $time,
            ));
        }
    }

    /**
     * @param  array<array-key, mixed>  $days
     * @return list<int>
     */
    private static function normaliseBusinessDays(array $days): array
    {
        if ($days === []) {
            throw new InvalidArgumentException('At least one SLA business day must be configured.');
        }

        $normalised = [];

        foreach ($days as $day) {
            if (! is_int($day) || $day < CarbonInterface::SUNDAY || $day > CarbonInterface::SATURDAY) {
                throw new InvalidArgumentException(sprintf(
                    'SLA business days must be Carbon day constants (0–6), "%s" given.',
                    get_debug_type($day) === 'int' ? $day : get_debug_type($day),
                ));
            }

            $normalised[$day] = $day;
        }

        ksort($normalised);

        return array_values($normalised);
    }

    /**
     * @param  array<array-key, mixed>  $durations
     * @return array<string, int>
     */
    private static function normalisePriorityDurations(array $durations): array
    {
        $normalised = [];

        foreach ($durations as $priority => $hours) {
            if (TicketPriority::tryFrom((string) $priority) === null) {
                throw new InvalidArgumentException(sprintf('Unknown ticket priority "%s" in SLA durations.', $priority));
            }

            if (! is_int($hours) || $hours <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'SLA duration for priority "%s" must be a positive integer number of hours.',
                    $priority,
                ));
            }

            $normalised[(string) $priority] = $hours;
        }

        return $normalised;
    }

    /**
     * @param  array<array-key, mixed>  $holidays
     * @return array<string, true>
     */
    private static function normaliseHolidays(array $holidays): array
    {
        $lookup = [];

        foreach ($holidays as $holiday) {
            $date = is_string($holiday) ? CarbonImmutable::createFromFormat('!Y-m-d', $holiday, 'UTC') : null;

            // Round-trip check rejects overflowing dates such as 2026-02-30.
            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $holiday) {
                throw new InvalidArgumentException(sprintf(
                    'SLA holidays must be valid "YYYY-MM-DD" dates, "%s" given.',
                    is_string($holiday) ? $holiday : get_debug_type($holiday),
                ));
            }

            $lookup[$holiday] = true;
        }

        ksort($lookup);

        return $lookup;
    }
}
