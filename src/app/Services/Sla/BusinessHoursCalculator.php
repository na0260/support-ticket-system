<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Business-hours aware SLA date arithmetic.
 *
 * Algorithm (window slicing, O(number of calendar days spanned)):
 *  1. Convert the input instant to the SLA timezone and drop sub-second precision.
 *  2. Snap it forward to the next business moment (inside a business window).
 *  3. Repeatedly consume the remaining time of the current day's window; if the
 *     remainder fits, add it and stop; otherwise jump to the next business day's
 *     opening time (skipping non-working weekdays and holidays).
 *
 * Arithmetic is done in whole seconds so that inputs with seconds (e.g.
 * 17:00:30) keep their exact offset. Window bounds are [start, end): start is
 * inclusive, end exclusive. When the remaining time equals exactly what is
 * left in the window, the result is the closing instant (e.g. 18:00) and not
 * the next morning.
 *
 * Results are returned as CarbonImmutable in the *input's* timezone; inputs
 * are never mutated. PHP's default timezone has no influence on the result.
 */
final readonly class BusinessHoursCalculator
{
    /** Safety valve against pathological calendars (≈10 years of consecutive non-working days). */
    private const int MAX_DAYS_SCANNED = 3660;

    public function __construct(
        private SlaSettings $settings,
    ) {}

    public function settings(): SlaSettings
    {
        return $this->settings;
    }

    /**
     * @param  int|TicketPriority  $priorityOrHours  A priority (resolved via config) or a number of business hours.
     */
    public function calculateDueAt(CarbonInterface $startDate, int|TicketPriority $priorityOrHours): CarbonImmutable
    {
        return $this->addBusinessSeconds($startDate, $this->resolveMinutes($priorityOrHours) * 60);
    }

    /**
     * Instant at which $thresholdPercentage of the business time between start and due has elapsed.
     *
     * @param  float|null  $thresholdPercentage  Fraction in (0, 1]; defaults to the configured threshold.
     */
    public function calculateWarningAt(
        CarbonInterface $startDate,
        CarbonImmutable $dueAt,
        ?float $thresholdPercentage = null,
    ): CarbonImmutable {
        $threshold = $thresholdPercentage ?? $this->settings->warningThreshold;
        SlaSettings::assertThreshold($threshold);

        $totalSeconds = $this->businessSecondsBetween($startDate, $dueAt);

        // round() first absorbs float noise (e.g. 0.8 * 7200 = 5759.999…) before flooring.
        $warningSeconds = (int) floor(round($totalSeconds * $threshold, 6));

        return $this->addBusinessSeconds($startDate, $warningSeconds);
    }

    /**
     * Whole business minutes elapsing in [$from, $to). Zero when $to <= $from.
     */
    public function businessMinutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return intdiv($this->businessSecondsBetween($from, $to), 60);
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        $local = $this->toSlaTimezone($date);

        return $this->settings->isBusinessWeekday($local->dayOfWeek)
            && ! $this->settings->isHoliday($local);
    }

    public function isWithinBusinessHours(CarbonInterface $date): bool
    {
        $local = $this->toSlaTimezone($date);

        return $this->isBusinessDay($local)
            && $local->greaterThanOrEqualTo($this->openingOf($local))
            && $local->lessThan($this->closingOf($local));
    }

    /**
     * Resolution target in business minutes.
     */
    public function resolveMinutes(int|TicketPriority $priorityOrHours): int
    {
        $hours = $priorityOrHours instanceof TicketPriority
            ? $this->settings->hoursFor($priorityOrHours)
            : $priorityOrHours;

        if ($hours <= 0) {
            throw new InvalidArgumentException(sprintf('SLA duration must be a positive number of hours, %d given.', $hours));
        }

        return $hours * 60;
    }

    private function addBusinessSeconds(CarbonInterface $startDate, int $seconds): CarbonImmutable
    {
        $cursor = $this->nextBusinessMoment($this->toSlaTimezone($startDate));
        $remaining = $seconds;

        while (true) {
            $available = $this->closingOf($cursor)->getTimestamp() - $cursor->getTimestamp();

            if ($remaining <= $available) {
                return $cursor->addSeconds($remaining)->setTimezone($startDate->getTimezone());
            }

            $remaining -= $available;
            $cursor = $this->nextBusinessDayOpening($cursor);
        }
    }

    private function businessSecondsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $end = $this->toSlaTimezone($to);
        $cursor = $this->nextBusinessMoment($this->toSlaTimezone($from));
        $total = 0;

        while ($cursor->lessThan($end)) {
            $segmentEnd = $this->closingOf($cursor)->min($end);
            $total += $segmentEnd->getTimestamp() - $cursor->getTimestamp();
            $cursor = $this->nextBusinessDayOpening($cursor);
        }

        return $total;
    }

    /**
     * $moment itself if it lies inside a business window, otherwise the next window's opening.
     */
    private function nextBusinessMoment(CarbonImmutable $moment): CarbonImmutable
    {
        if ($this->isBusinessDay($moment)) {
            $opening = $this->openingOf($moment);

            if ($moment->lessThan($opening)) {
                return $opening;
            }

            if ($moment->lessThan($this->closingOf($moment))) {
                return $moment;
            }
        }

        return $this->nextBusinessDayOpening($moment);
    }

    /**
     * Opening instant of the first business day strictly after $moment's calendar day.
     */
    private function nextBusinessDayOpening(CarbonImmutable $moment): CarbonImmutable
    {
        $day = $moment->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS_SCANNED; $i++) {
            $day = $day->addDay();

            if ($this->isBusinessDay($day)) {
                return $this->openingOf($day);
            }
        }

        throw new RuntimeException(sprintf(
            'No business day found within %d days after %s; check SLA business days and holidays.',
            self::MAX_DAYS_SCANNED,
            $moment->toDateString(),
        ));
    }

    private function openingOf(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimeFromTimeString($this->settings->businessHoursStart);
    }

    private function closingOf(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimeFromTimeString($this->settings->businessHoursEnd);
    }

    private function toSlaTimezone(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)
            ->setTimezone($this->settings->timezone)
            ->startOfSecond();
    }
}
