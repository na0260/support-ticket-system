<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sla;

use App\Enums\TicketPriority;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\Sla\SlaSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no framework boot). Reference calendar, November 2026:
 *
 *   Sun 01 | Mon 02 | Tue 03 | Wed 04 | Thu 05 | Fri 06 | Sat 07
 *   Sun 08 | Mon 09 | Tue 10 | Wed 11 | Thu 12 | Fri 13 | Sat 14 | Sun 15
 *
 * Work week Sun–Thu, 09:00–18:00 (540 business minutes per day).
 */
final class BusinessHoursCalculatorTest extends TestCase
{
    private const string TZ = 'UTC';

    private string $originalDefaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalDefaultTimezone);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // 1. Assessment prompt example
    // ---------------------------------------------------------------------

    #[Test]
    public function thursday_5pm_critical_ticket_rolls_over_the_weekend_and_is_due_sunday_10am(): void
    {
        $start = $this->at('2026-11-05 17:00'); // Thursday
        $this->assertSame(CarbonInterface::THURSDAY, $start->dayOfWeek);

        $due = $this->calculator()->calculateDueAt($start, TicketPriority::CRITICAL);

        // 60 min Thu (17:00→18:00) + skip Fri/Sat + 60 min Sun (09:00→10:00)
        $this->assertDateTime('2026-11-08 10:00:00', $due);
        $this->assertSame(CarbonInterface::SUNDAY, $due->dayOfWeek);
    }

    // ---------------------------------------------------------------------
    // 2. Weekend / off-hours creation
    // ---------------------------------------------------------------------

    #[Test]
    public function ticket_created_on_friday_starts_sunday_morning(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-06 14:00'), 2);

        $this->assertDateTime('2026-11-08 11:00:00', $due);
    }

    #[Test]
    public function ticket_created_on_saturday_starts_sunday_morning(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-07 23:59:59'), 2);

        $this->assertDateTime('2026-11-08 11:00:00', $due);
    }

    #[Test]
    public function ticket_created_before_shift_starts_at_opening_time(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-02 06:30'), TicketPriority::HIGH);

        $this->assertDateTime('2026-11-02 17:00:00', $due);
    }

    #[Test]
    public function ticket_created_after_shift_starts_next_business_morning(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-03 20:00'), 4);

        $this->assertDateTime('2026-11-04 13:00:00', $due);
    }

    #[Test]
    public function ticket_created_exactly_at_closing_time_starts_next_business_morning(): void
    {
        // 18:00 is an exclusive upper bound: no business time remains that day.
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-03 18:00'), 1);

        $this->assertDateTime('2026-11-04 10:00:00', $due);
    }

    #[Test]
    public function ticket_created_exactly_at_opening_time_starts_immediately(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-03 09:00'), 1);

        $this->assertDateTime('2026-11-03 10:00:00', $due);
    }

    #[Test]
    public function ticket_created_on_thursday_after_hours_skips_the_whole_weekend(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-05 19:15'), TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-08 11:00:00', $due);
    }

    // ---------------------------------------------------------------------
    // 3. Multi-day spanning & boundary arithmetic
    // ---------------------------------------------------------------------

    #[Test]
    public function medium_ticket_spans_two_full_days_plus_six_hours(): void
    {
        // 24h = 9h (Sun) + 9h (Mon) + 6h (Tue)
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-01 09:00'), TicketPriority::MEDIUM);

        $this->assertDateTime('2026-11-03 15:00:00', $due);
    }

    #[Test]
    public function sla_ending_exactly_at_closing_time_is_due_at_closing_not_next_morning(): void
    {
        // Wed 12:00: 6h (Wed) + 9h (Thu) + skip Fri/Sat + 9h (Sun) = 24h → Sun 18:00 exactly.
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-04 12:00'), 24);

        $this->assertDateTime('2026-11-08 18:00:00', $due);
    }

    #[Test]
    public function one_full_business_day_from_opening_ends_at_closing_same_day(): void
    {
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-02 09:00'), 9);

        $this->assertDateTime('2026-11-02 18:00:00', $due);
    }

    #[Test]
    public function low_priority_ticket_spans_a_full_work_week_and_a_weekend(): void
    {
        // 48h = 5 × 9h (Sun–Thu) + skip Fri/Sat + 3h (Sun)
        $due = $this->calculator()->calculateDueAt($this->at('2026-11-01 09:00'), TicketPriority::LOW);

        $this->assertDateTime('2026-11-08 12:00:00', $due);
    }

    #[Test]
    public function seconds_are_preserved_and_microseconds_discarded(): void
    {
        $due = $this->calculator()->calculateDueAt(
            CarbonImmutable::parse('2026-11-05 17:00:30.987654', self::TZ),
            TicketPriority::CRITICAL,
        );

        $this->assertSame('2026-11-08 10:00:30.000000', $due->format('Y-m-d H:i:s.u'));
    }

    /**
     * @return array<string, array{string, TicketPriority, string}>
     */
    public static function priorityMatrix(): array
    {
        return [
            'critical from Sunday open' => ['2026-11-01 09:00', TicketPriority::CRITICAL, '2026-11-01 11:00:00'],
            'high from Sunday open' => ['2026-11-01 09:00', TicketPriority::HIGH, '2026-11-01 17:00:00'],
            'medium from Sunday open' => ['2026-11-01 09:00', TicketPriority::MEDIUM, '2026-11-03 15:00:00'],
            'low from Sunday open' => ['2026-11-01 09:00', TicketPriority::LOW, '2026-11-08 12:00:00'],
            'critical from Thursday 17:00' => ['2026-11-05 17:00', TicketPriority::CRITICAL, '2026-11-08 10:00:00'],
            'high from Thursday 17:00' => ['2026-11-05 17:00', TicketPriority::HIGH, '2026-11-08 16:00:00'],
            'medium from Thursday 17:00' => ['2026-11-05 17:00', TicketPriority::MEDIUM, '2026-11-10 14:00:00'],
            'low from Thursday 17:00' => ['2026-11-05 17:00', TicketPriority::LOW, '2026-11-15 11:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('priorityMatrix')]
    public function it_computes_due_dates_for_every_priority(string $start, TicketPriority $priority, string $expected): void
    {
        $this->assertDateTime($expected, $this->calculator()->calculateDueAt($this->at($start), $priority));
    }

    // ---------------------------------------------------------------------
    // 4. Holiday blackout handling
    // ---------------------------------------------------------------------

    #[Test]
    public function holiday_on_sunday_is_skipped_and_work_resumes_monday_morning(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-08']);

        // Created Friday (weekend) → Sun is a holiday → clock starts Mon 09:00.
        $due = $calculator->calculateDueAt($this->at('2026-11-06 14:00'), TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-09 11:00:00', $due);
        $this->assertSame(CarbonInterface::MONDAY, $due->dayOfWeek);
    }

    #[Test]
    public function holiday_interrupting_a_running_sla_window_is_skipped(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-08']);

        // 60 min Thu → skip Fri, Sat, Sun(holiday) → 60 min Mon
        $due = $calculator->calculateDueAt($this->at('2026-11-05 17:00'), TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-09 10:00:00', $due);
    }

    #[Test]
    public function ticket_created_during_business_hours_on_a_holiday_waits_for_next_business_day(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-03']); // Tuesday

        $due = $calculator->calculateDueAt($this->at('2026-11-03 11:00'), 2);

        $this->assertDateTime('2026-11-04 11:00:00', $due);
    }

    #[Test]
    public function consecutive_holidays_adjacent_to_a_weekend_are_all_skipped(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-05', '2026-11-08', '2026-11-09']); // Thu, Sun, Mon

        // Wed 17:00: 60 min Wed → skip Thu(h), Fri, Sat, Sun(h), Mon(h) → 60 min Tue
        $due = $calculator->calculateDueAt($this->at('2026-11-04 17:00'), TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-10 10:00:00', $due);
    }

    #[Test]
    public function multi_day_sla_absorbs_holidays_in_the_middle_of_the_window(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-02']); // Monday

        // 24h from Sun 09:00: 9h Sun, skip Mon(h), 9h Tue, 6h Wed
        $due = $calculator->calculateDueAt($this->at('2026-11-01 09:00'), TicketPriority::MEDIUM);

        $this->assertDateTime('2026-11-04 15:00:00', $due);
    }

    // ---------------------------------------------------------------------
    // 5. Warning threshold (80%)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{TicketPriority, string, string}>
     */
    public static function warningMatrix(): array
    {
        // Start Sun 09:00. 80% of: 120→96 min, 480→384 min, 1440→1152 min, 2880→2304 min.
        return [
            'critical' => [TicketPriority::CRITICAL, '2026-11-01 11:00:00', '2026-11-01 10:36:00'],
            'high' => [TicketPriority::HIGH, '2026-11-01 17:00:00', '2026-11-01 15:24:00'],
            'medium' => [TicketPriority::MEDIUM, '2026-11-03 15:00:00', '2026-11-03 10:12:00'],
            'low' => [TicketPriority::LOW, '2026-11-08 12:00:00', '2026-11-05 11:24:00'],
        ];
    }

    #[Test]
    #[DataProvider('warningMatrix')]
    public function warning_triggers_after_80_percent_of_business_time_for_every_priority(
        TicketPriority $priority,
        string $expectedDue,
        string $expectedWarning,
    ): void {
        $calculator = $this->calculator();
        $start = $this->at('2026-11-01 09:00');

        $due = $calculator->calculateDueAt($start, $priority);
        $warning = $calculator->calculateWarningAt($start, $due);

        $this->assertDateTime($expectedDue, $due);
        $this->assertDateTime($expectedWarning, $warning);

        $total = $calculator->businessMinutesBetween($start, $due);
        $this->assertSame($priority->slaHours() * 60, $total);
        $this->assertSame((int) ($total * 0.8), $calculator->businessMinutesBetween($start, $warning));
    }

    #[Test]
    public function warning_point_respects_weekends_inside_the_window(): void
    {
        $calculator = $this->calculator();
        $start = $this->at('2026-11-05 17:00');
        $due = $calculator->calculateDueAt($start, TicketPriority::CRITICAL);

        // 96 min = 60 Thu + 36 Sun
        $this->assertDateTime('2026-11-08 09:36:00', $calculator->calculateWarningAt($start, $due));
    }

    #[Test]
    public function warning_for_off_hours_ticket_is_measured_from_the_first_business_window(): void
    {
        $calculator = $this->calculator();
        $start = $this->at('2026-11-06 14:00'); // Friday
        $due = $calculator->calculateDueAt($start, TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-08 10:36:00', $calculator->calculateWarningAt($start, $due));
    }

    #[Test]
    public function warning_threshold_can_be_overridden_per_call_and_per_configuration(): void
    {
        $start = $this->at('2026-11-01 09:00');

        $default = $this->calculator();
        $due = $default->calculateDueAt($start, TicketPriority::HIGH);
        $this->assertDateTime('2026-11-01 13:00:00', $default->calculateWarningAt($start, $due, 0.5));
        $this->assertDateTime('2026-11-01 17:00:00', $default->calculateWarningAt($start, $due, 1.0));

        $configured = $this->calculator(warningThreshold: 0.75);
        $this->assertDateTime('2026-11-01 15:00:00', $configured->calculateWarningAt($start, $due));
    }

    #[Test]
    public function warning_threshold_must_be_a_fraction_between_zero_and_one(): void
    {
        $calculator = $this->calculator();
        $start = $this->at('2026-11-01 09:00');
        $due = $calculator->calculateDueAt($start, 2);

        $this->expectException(InvalidArgumentException::class);

        $calculator->calculateWarningAt($start, $due, 80.0);
    }

    // ---------------------------------------------------------------------
    // Business minutes between two instants
    // ---------------------------------------------------------------------

    #[Test]
    public function it_counts_only_business_minutes_between_two_instants(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(120, $calculator->businessMinutesBetween($this->at('2026-11-05 17:00'), $this->at('2026-11-08 10:00')));
        $this->assertSame(60, $calculator->businessMinutesBetween($this->at('2026-11-05 17:00'), $this->at('2026-11-07 12:00')));
        $this->assertSame(540, $calculator->businessMinutesBetween($this->at('2026-11-02 00:00'), $this->at('2026-11-03 00:00')));
        $this->assertSame(0, $calculator->businessMinutesBetween($this->at('2026-11-06 00:00'), $this->at('2026-11-07 23:59')));
    }

    #[Test]
    public function business_minutes_between_is_zero_when_end_is_not_after_start(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(0, $calculator->businessMinutesBetween($this->at('2026-11-02 12:00'), $this->at('2026-11-02 12:00')));
        $this->assertSame(0, $calculator->businessMinutesBetween($this->at('2026-11-02 12:00'), $this->at('2026-11-02 10:00')));
    }

    // ---------------------------------------------------------------------
    // Calendar predicates
    // ---------------------------------------------------------------------

    #[Test]
    public function it_identifies_business_days(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-03']);

        $this->assertTrue($calculator->isBusinessDay($this->at('2026-11-01 03:00'))); // Sun
        $this->assertTrue($calculator->isBusinessDay($this->at('2026-11-05 23:00'))); // Thu
        $this->assertFalse($calculator->isBusinessDay($this->at('2026-11-06 12:00'))); // Fri
        $this->assertFalse($calculator->isBusinessDay($this->at('2026-11-07 12:00'))); // Sat
        $this->assertFalse($calculator->isBusinessDay($this->at('2026-11-03 12:00'))); // holiday
    }

    #[Test]
    public function it_identifies_business_hours_with_inclusive_start_and_exclusive_end(): void
    {
        $calculator = $this->calculator(holidays: ['2026-11-03']);

        $this->assertFalse($calculator->isWithinBusinessHours($this->at('2026-11-02 08:59:59')));
        $this->assertTrue($calculator->isWithinBusinessHours($this->at('2026-11-02 09:00:00')));
        $this->assertTrue($calculator->isWithinBusinessHours($this->at('2026-11-02 17:59:59')));
        $this->assertFalse($calculator->isWithinBusinessHours($this->at('2026-11-02 18:00:00')));
        $this->assertFalse($calculator->isWithinBusinessHours($this->at('2026-11-06 12:00:00'))); // Fri
        $this->assertFalse($calculator->isWithinBusinessHours($this->at('2026-11-03 12:00:00'))); // holiday
    }

    #[Test]
    public function calendar_predicates_evaluate_in_the_sla_timezone(): void
    {
        $calculator = $this->calculator(timezone: 'Asia/Dhaka'); // UTC+6

        // Thu 2026-11-05 20:00 UTC = Fri 02:00 Dhaka → not a business day in Dhaka.
        $this->assertFalse($calculator->isBusinessDay(CarbonImmutable::parse('2026-11-05 20:00', 'UTC')));
        // Thu 03:00 UTC = Thu 09:00 Dhaka → opening minute.
        $this->assertTrue($calculator->isWithinBusinessHours(CarbonImmutable::parse('2026-11-05 03:00', 'UTC')));
    }

    // ---------------------------------------------------------------------
    // Determinism, immutability, configuration
    // ---------------------------------------------------------------------

    #[Test]
    public function result_is_independent_of_the_php_default_timezone(): void
    {
        $start = CarbonImmutable::parse('2026-11-05 17:00', self::TZ);
        $expected = $this->calculator()->calculateDueAt($start, TicketPriority::CRITICAL);

        foreach (['America/New_York', 'Pacific/Kiritimati', 'Asia/Dhaka', 'Pacific/Pago_Pago'] as $serverTz) {
            date_default_timezone_set($serverTz);

            $actual = $this->calculator()->calculateDueAt($start, TicketPriority::CRITICAL);

            $this->assertTrue($expected->equalTo($actual), "Drift under server timezone {$serverTz}");
        }
    }

    #[Test]
    public function business_hours_are_applied_in_the_sla_timezone_and_returned_in_the_input_timezone(): void
    {
        $calculator = $this->calculator(timezone: 'Asia/Dhaka');

        // Thu 11:00 UTC = Thu 17:00 Dhaka → due Sun 10:00 Dhaka = Sun 04:00 UTC.
        $due = $calculator->calculateDueAt(CarbonImmutable::parse('2026-11-05 11:00', 'UTC'), TicketPriority::CRITICAL);

        $this->assertSame('UTC', $due->getTimezone()->getName());
        $this->assertSame('2026-11-08 04:00:00', $due->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 10:00:00', $due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_never_mutates_the_input_and_always_returns_an_immutable_instance(): void
    {
        $mutable = Carbon::parse('2026-11-05 17:00', self::TZ);

        $due = $this->calculator()->calculateDueAt($mutable, TicketPriority::CRITICAL);

        $this->assertInstanceOf(CarbonImmutable::class, $due);
        $this->assertSame('2026-11-05 17:00:00', $mutable->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function priority_durations_are_read_from_configuration(): void
    {
        $calculator = $this->calculator(priorityDurations: [TicketPriority::CRITICAL->value => 4]);

        $due = $calculator->calculateDueAt($this->at('2026-11-01 09:00'), TicketPriority::CRITICAL);

        $this->assertDateTime('2026-11-01 13:00:00', $due);
        $this->assertSame(240, $calculator->resolveMinutes(TicketPriority::CRITICAL));
        // Unconfigured priorities fall back to the enum default policy.
        $this->assertSame(TicketPriority::LOW->slaHours() * 60, $calculator->resolveMinutes(TicketPriority::LOW));
    }

    #[Test]
    public function custom_business_week_and_hours_are_honoured(): void
    {
        $calculator = new BusinessHoursCalculator(new SlaSettings(
            timezone: self::TZ,
            businessDays: [CarbonInterface::MONDAY, CarbonInterface::TUESDAY, CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY, CarbonInterface::FRIDAY],
            businessHoursStart: '08:30',
            businessHoursEnd: '12:30',
        ));

        // Fri 12:00 → 30 min Fri → skip Sat/Sun → 90 min Mon from 08:30
        $due = $calculator->calculateDueAt($this->at('2026-11-06 12:00'), 2);

        $this->assertDateTime('2026-11-09 10:00:00', $due);
    }

    #[Test]
    public function sla_duration_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->calculateDueAt($this->at('2026-11-01 09:00'), 0);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): array
    {
        return [
            'end before start' => [['businessHoursStart' => '18:00', 'businessHoursEnd' => '09:00']],
            'zero-length day' => [['businessHoursStart' => '09:00', 'businessHoursEnd' => '09:00']],
            'malformed time' => [['businessHoursStart' => '9am']],
            'no business days' => [['businessDays' => []]],
            'invalid weekday' => [['businessDays' => [7]]],
            'malformed holiday' => [['holidays' => ['08/11/2026']]],
            'impossible holiday' => [['holidays' => ['2026-02-30']]],
            'invalid timezone' => [['timezone' => 'Mars/Olympus']],
            'threshold above one' => [['warningThreshold' => 1.5]],
            'non-positive priority hours' => [['priorityDurations' => ['critical' => 0]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidSettings')]
    public function invalid_settings_are_rejected_at_construction(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlaSettings(...array_merge(['timezone' => self::TZ], $overrides));
    }

    #[Test]
    public function settings_can_be_built_from_the_config_array(): void
    {
        $settings = SlaSettings::fromConfig([
            'timezone' => null,
            'business_days' => [0, 1, 2, 3, 4],
            'business_hours' => ['start' => '09:00', 'end' => '18:00'],
            'priority_durations' => ['critical' => '3'],
            'warning_threshold_percentage' => '0.9',
            'holidays' => ['2026-12-16'],
        ], fallbackTimezone: 'Asia/Dhaka');

        $this->assertSame('Asia/Dhaka', $settings->timezone);
        $this->assertSame(540, $settings->businessMinutesPerDay());
        $this->assertSame(3, $settings->priorityDurations['critical']);
        $this->assertSame(0.9, $settings->warningThreshold);
        $this->assertTrue($settings->isHoliday(CarbonImmutable::parse('2026-12-16 10:00', 'Asia/Dhaka')));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $holidays
     * @param  array<string, int>  $priorityDurations
     */
    private function calculator(
        array $holidays = [],
        string $timezone = self::TZ,
        float $warningThreshold = 0.80,
        array $priorityDurations = [],
    ): BusinessHoursCalculator {
        return new BusinessHoursCalculator(new SlaSettings(
            timezone: $timezone,
            businessDays: [
                CarbonInterface::SUNDAY,
                CarbonInterface::MONDAY,
                CarbonInterface::TUESDAY,
                CarbonInterface::WEDNESDAY,
                CarbonInterface::THURSDAY,
            ],
            businessHoursStart: '09:00',
            businessHoursEnd: '18:00',
            priorityDurations: $priorityDurations,
            warningThreshold: $warningThreshold,
            holidays: $holidays,
        ));
    }

    private function at(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, self::TZ);
    }

    private function assertDateTime(string $expected, CarbonInterface $actual): void
    {
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'), sprintf('Got %s', $actual->format('l Y-m-d H:i:s T')));
    }
}
