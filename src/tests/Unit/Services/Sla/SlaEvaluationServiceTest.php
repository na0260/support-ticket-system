<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sla;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\Sla\SlaEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reference: Thu 2026-11-05 17:00 + CRITICAL (2h) → due Sun 2026-11-08 10:00,
 * warning at 80% (96 business minutes) → Sun 2026-11-08 09:36.
 */
final class SlaEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const string CREATED = '2026-11-05 17:00:00';

    private const string WARNING = '2026-11-08 09:36:00';

    private const string DUE = '2026-11-08 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'sla.timezone' => 'UTC',
            'sla.business_days' => [0, 1, 2, 3, 4],
            'sla.business_hours' => ['start' => '09:00', 'end' => '18:00'],
            'sla.warning_threshold_percentage' => 0.80,
            'sla.holidays' => [],
        ]);
        date_default_timezone_set('UTC');
    }

    // ---------------------------------------------------------------------
    // applySla
    // ---------------------------------------------------------------------

    #[Test]
    public function apply_sla_assigns_due_date_warning_date_and_status(): void
    {
        $this->travelTo(self::CREATED);

        $ticket = $this->service()->applySla($this->makeTicket(TicketPriority::CRITICAL));

        $this->assertSame(self::DUE, $ticket->sla_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::WARNING, $ticket->sla_warning_at?->format('Y-m-d H:i:s'));
        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);
    }

    #[Test]
    public function apply_sla_uses_the_explicit_start_date_over_created_at_and_now(): void
    {
        $this->travelTo('2026-11-02 12:00:00');
        $ticket = $this->makeTicket(TicketPriority::HIGH);
        $ticket->created_at = CarbonImmutable::parse('2026-11-01 09:00:00');

        $this->service()->applySla($ticket, CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->assertSame('2026-11-03 17:00:00', $ticket->sla_due_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function apply_sla_falls_back_to_created_at_when_no_start_date_is_given(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $ticket = $this->makeTicket(TicketPriority::HIGH);
        $ticket->created_at = CarbonImmutable::parse('2026-11-01 09:00:00');

        $this->service()->applySla($ticket);

        $this->assertSame('2026-11-01 17:00:00', $ticket->sla_due_at->format('Y-m-d H:i:s'));
        // Backdated ticket: 3h of business time consumed out of 8h → still within SLA.
        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);
    }

    #[Test]
    public function apply_sla_on_a_backdated_ticket_evaluates_status_against_now(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $ticket = $this->makeTicket(TicketPriority::CRITICAL);

        $this->service()->applySla($ticket, CarbonImmutable::parse(self::CREATED));

        $this->assertSame(SlaStatus::BREACHED, $ticket->sla_status);
    }

    #[Test]
    public function apply_sla_honours_configured_holidays_through_the_container(): void
    {
        config(['sla.holidays' => ['2026-11-08']]);
        $this->travelTo(self::CREATED);

        $ticket = $this->service()->applySla($this->makeTicket(TicketPriority::CRITICAL));

        $this->assertSame('2026-11-09 10:00:00', $ticket->sla_due_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function apply_sla_honours_configured_priority_durations(): void
    {
        config(['sla.priority_durations' => [TicketPriority::CRITICAL->value => 1]]);
        $this->travelTo('2026-11-01 09:00:00');

        $ticket = $this->service()->applySla($this->makeTicket(TicketPriority::CRITICAL));

        $this->assertSame('2026-11-01 10:00:00', $ticket->sla_due_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function sla_dates_persist_correctly_when_sla_timezone_differs_from_app_timezone(): void
    {
        config(['sla.timezone' => 'Asia/Dhaka']); // UTC+6, app stays UTC

        // 11:00 UTC = 17:00 Dhaka (Thu) → due Sun 10:00 Dhaka = Sun 04:00 UTC
        $this->travelTo('2026-11-05 11:00:00');
        $ticket = $this->service()->applySla(Ticket::factory()->make([
            'priority' => TicketPriority::CRITICAL,
            'sla_due_at' => null,
        ]));
        $ticket->save();

        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('2026-11-08 04:00:00', $fresh->sla_due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 03:36:00', $fresh->sla_warning_at?->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------------
    // Status transitions for active tickets
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, SlaStatus}>
     */
    public static function transitionTimeline(): array
    {
        return [
            'at creation' => [self::CREATED, SlaStatus::WITHIN_SLA],
            'over the weekend' => ['2026-11-07 12:00:00', SlaStatus::WITHIN_SLA],
            'one second before warning' => ['2026-11-08 09:35:59', SlaStatus::WITHIN_SLA],
            'exactly at warning' => [self::WARNING, SlaStatus::APPROACHING_BREACH],
            'one second before due' => ['2026-11-08 09:59:59', SlaStatus::APPROACHING_BREACH],
            'exactly at due' => [self::DUE, SlaStatus::BREACHED],
            'long after due' => ['2026-12-01 00:00:00', SlaStatus::BREACHED],
        ];
    }

    #[Test]
    #[DataProvider('transitionTimeline')]
    public function active_ticket_transitions_within_to_approaching_to_breached(string $now, SlaStatus $expected): void
    {
        $ticket = $this->slaTicket(TicketStatus::IN_PROGRESS);

        $this->assertSame($expected, $this->service()->evaluate($ticket, CarbonImmutable::parse($now)));
    }

    #[Test]
    public function evaluate_defaults_now_to_the_application_clock(): void
    {
        $ticket = $this->slaTicket(TicketStatus::OPEN);

        $this->travelTo('2026-11-08 09:40:00');
        $this->assertSame(SlaStatus::APPROACHING_BREACH, $this->service()->evaluate($ticket));

        $this->travelTo('2026-11-08 10:00:01');
        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($ticket));
    }

    #[Test]
    public function evaluate_compares_absolute_instants_across_timezones(): void
    {
        $ticket = $this->slaTicket(TicketStatus::OPEN);

        // 2026-11-08 15:59 in Dhaka = 09:59 UTC → before due (10:00 UTC).
        $this->assertSame(
            SlaStatus::APPROACHING_BREACH,
            $this->service()->evaluate($ticket, CarbonImmutable::parse('2026-11-08 15:59:00', 'Asia/Dhaka')),
        );
    }

    #[Test]
    public function ticket_without_a_warning_date_goes_straight_from_within_to_breached(): void
    {
        $ticket = $this->slaTicket(TicketStatus::OPEN);
        $ticket->sla_warning_at = null;

        $this->assertSame(SlaStatus::WITHIN_SLA, $this->service()->evaluate($ticket, CarbonImmutable::parse('2026-11-08 09:59:00')));
        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($ticket, CarbonImmutable::parse(self::DUE)));
    }

    #[Test]
    public function evaluating_a_ticket_without_sla_dates_fails_loudly(): void
    {
        $this->expectException(LogicException::class);

        $this->service()->evaluate($this->makeTicket(TicketPriority::LOW));
    }

    // ---------------------------------------------------------------------
    // Frozen SLA for resolved / closed tickets
    // ---------------------------------------------------------------------

    #[Test]
    public function resolved_ticket_freezes_within_sla_even_long_after_the_due_date(): void
    {
        $ticket = $this->slaTicket(TicketStatus::RESOLVED, resolvedAt: '2026-11-08 09:50:00');

        $this->assertSame(SlaStatus::WITHIN_SLA, $this->service()->evaluate($ticket, CarbonImmutable::parse('2027-01-01')));
    }

    #[Test]
    public function resolved_ticket_freezes_breached_when_resolved_after_the_due_date(): void
    {
        $ticket = $this->slaTicket(TicketStatus::RESOLVED, resolvedAt: '2026-11-08 10:00:01');

        // Even if "now" is (hypothetically) before the due date, the frozen outcome wins.
        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($ticket, CarbonImmutable::parse(self::CREATED)));
    }

    #[Test]
    public function resolving_exactly_at_the_due_instant_meets_the_sla(): void
    {
        $ticket = $this->slaTicket(TicketStatus::RESOLVED, resolvedAt: self::DUE);

        $this->assertSame(SlaStatus::WITHIN_SLA, $this->service()->evaluate($ticket, CarbonImmutable::parse('2027-01-01')));
    }

    #[Test]
    public function closed_ticket_is_frozen_on_its_resolution_time(): void
    {
        $met = $this->slaTicket(TicketStatus::CLOSED, resolvedAt: '2026-11-08 09:00:00', closedAt: '2026-11-20 09:00:00');
        $missed = $this->slaTicket(TicketStatus::CLOSED, resolvedAt: '2026-11-09 09:00:00', closedAt: '2026-11-09 10:00:00');

        $this->assertSame(SlaStatus::WITHIN_SLA, $this->service()->evaluate($met, CarbonImmutable::parse('2027-01-01')));
        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($missed, CarbonImmutable::parse('2027-01-01')));
    }

    #[Test]
    public function closed_ticket_without_resolution_time_is_frozen_on_its_closing_time(): void
    {
        $closedLate = $this->slaTicket(TicketStatus::CLOSED, closedAt: '2026-11-09 09:00:00');
        $closedEarly = $this->slaTicket(TicketStatus::CLOSED, closedAt: '2026-11-06 09:00:00');

        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($closedLate));
        $this->assertSame(SlaStatus::WITHIN_SLA, $this->service()->evaluate($closedEarly));
    }

    #[Test]
    public function completed_ticket_without_any_completion_time_keeps_its_last_recorded_status(): void
    {
        $ticket = $this->slaTicket(TicketStatus::CLOSED);
        $ticket->sla_status = SlaStatus::BREACHED;

        $this->assertSame(SlaStatus::BREACHED, $this->service()->evaluate($ticket, CarbonImmutable::parse(self::CREATED)));
    }

    // ---------------------------------------------------------------------
    // Ticket model helpers
    // ---------------------------------------------------------------------

    #[Test]
    public function ticket_helpers_reflect_the_live_sla_state(): void
    {
        $ticket = $this->slaTicket(TicketStatus::IN_PROGRESS);

        $this->travelTo('2026-11-05 17:30:00');
        $this->assertFalse($ticket->isSlaApproachingBreach());
        $this->assertFalse($ticket->isSlaBreached());

        $this->travelTo('2026-11-08 09:45:00');
        $this->assertTrue($ticket->isSlaApproachingBreach());
        $this->assertFalse($ticket->isSlaBreached());

        $this->travelTo('2026-11-08 10:00:00');
        $this->assertFalse($ticket->isSlaApproachingBreach());
        $this->assertTrue($ticket->isSlaBreached());
    }

    #[Test]
    public function ticket_helpers_accept_an_explicit_reference_time(): void
    {
        $ticket = $this->slaTicket(TicketStatus::OPEN);

        $this->assertTrue($ticket->isSlaBreached(CarbonImmutable::parse('2026-11-09 00:00:00')));
        $this->assertTrue($ticket->isSlaApproachingBreach(CarbonImmutable::parse(self::WARNING)));
    }

    #[Test]
    public function remaining_business_minutes_ignore_nights_weekends_and_holidays(): void
    {
        $ticket = $this->slaTicket(TicketStatus::IN_PROGRESS);

        $this->assertSame(120, $ticket->remainingBusinessMinutes(CarbonImmutable::parse(self::CREATED)));
        $this->assertSame(90, $ticket->remainingBusinessMinutes(CarbonImmutable::parse('2026-11-05 17:30:00')));
        $this->assertSame(60, $ticket->remainingBusinessMinutes(CarbonImmutable::parse('2026-11-05 22:00:00')));
        $this->assertSame(60, $ticket->remainingBusinessMinutes(CarbonImmutable::parse('2026-11-07 12:00:00')));
        $this->assertSame(15, $ticket->remainingBusinessMinutes(CarbonImmutable::parse('2026-11-08 09:45:00')));
        $this->assertSame(0, $ticket->remainingBusinessMinutes(CarbonImmutable::parse(self::DUE)));
        $this->assertSame(0, $ticket->remainingBusinessMinutes(CarbonImmutable::parse('2026-11-20 12:00:00')));
    }

    #[Test]
    public function remaining_business_minutes_default_to_the_application_clock(): void
    {
        $ticket = $this->slaTicket(TicketStatus::OPEN);
        $this->travelTo('2026-11-08 09:30:00');

        $this->assertSame(30, $ticket->remainingBusinessMinutes());
    }

    #[Test]
    public function remaining_business_minutes_are_zero_once_the_clock_is_stopped(): void
    {
        $resolved = $this->slaTicket(TicketStatus::RESOLVED, resolvedAt: '2026-11-05 17:30:00');
        $closed = $this->slaTicket(TicketStatus::CLOSED, closedAt: '2026-11-05 17:30:00');

        $this->assertSame(0, $resolved->remainingBusinessMinutes(CarbonImmutable::parse(self::CREATED)));
        $this->assertSame(0, $closed->remainingBusinessMinutes(CarbonImmutable::parse(self::CREATED)));
    }

    #[Test]
    public function ticket_is_resolved_helper_delegates_to_status(): void
    {
        $this->assertTrue($this->makeTicket(TicketPriority::LOW, TicketStatus::RESOLVED)->isResolved());
        $this->assertFalse($this->makeTicket(TicketPriority::LOW, TicketStatus::CLOSED)->isResolved());
        $this->assertFalse($this->makeTicket(TicketPriority::LOW, TicketStatus::OPEN)->isResolved());
    }

    #[Test]
    public function the_calculator_is_resolved_from_the_container_with_live_configuration(): void
    {
        config(['sla.business_hours' => ['start' => '10:00', 'end' => '12:00']]);

        $due = $this->app->make(BusinessHoursCalculator::class)
            ->calculateDueAt(CarbonImmutable::parse('2026-11-01 10:00:00'), 3);

        // 2h Sun + 1h Mon
        $this->assertSame('2026-11-02 11:00:00', $due->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function service(): SlaEvaluationService
    {
        return $this->app->make(SlaEvaluationService::class);
    }

    private function makeTicket(TicketPriority $priority, TicketStatus $status = TicketStatus::OPEN): Ticket
    {
        $ticket = new Ticket;
        $ticket->priority = $priority;
        $ticket->status = $status;

        return $ticket;
    }

    /**
     * Thu 17:00 CRITICAL ticket with pre-computed SLA dates (see class docblock).
     */
    private function slaTicket(TicketStatus $status, ?string $resolvedAt = null, ?string $closedAt = null): Ticket
    {
        $ticket = $this->makeTicket(TicketPriority::CRITICAL, $status);
        $ticket->created_at = CarbonImmutable::parse(self::CREATED);
        $ticket->sla_due_at = CarbonImmutable::parse(self::DUE);
        $ticket->sla_warning_at = CarbonImmutable::parse(self::WARNING);
        $ticket->sla_status = SlaStatus::WITHIN_SLA;
        $ticket->resolved_at = $resolvedAt !== null ? CarbonImmutable::parse($resolvedAt) : null;
        $ticket->closed_at = $closedAt !== null ? CarbonImmutable::parse($closedAt) : null;

        return $ticket;
    }
}
