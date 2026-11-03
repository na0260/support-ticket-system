<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sla;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketSlaStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_evaluates_within_sla_when_time_is_before_warning_threshold(): void
    {
        $dueAt = Carbon::parse('2026-11-05 18:00:00');
        $warningAt = Carbon::parse('2026-11-05 15:00:00');

        $ticket = Ticket::factory()->make([
            'status' => TicketStatus::OPEN,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
        ]);

        $evalTime = Carbon::parse('2026-11-05 12:00:00'); // Before warning
        $this->assertSame('within_sla', $ticket->getSlaState($evalTime));
    }

    #[Test]
    public function it_evaluates_approaching_breach_when_time_is_between_warning_and_due(): void
    {
        $dueAt = Carbon::parse('2026-11-05 18:00:00');
        $warningAt = Carbon::parse('2026-11-05 15:00:00');

        $ticket = Ticket::factory()->make([
            'status' => TicketStatus::IN_PROGRESS,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
        ]);

        // Exactly at warning instant
        $evalTime1 = Carbon::parse('2026-11-05 15:00:00');
        $this->assertSame('approaching_breach', $ticket->getSlaState($evalTime1));

        // Between warning and due
        $evalTime2 = Carbon::parse('2026-11-05 17:30:00');
        $this->assertSame('approaching_breach', $ticket->getSlaState($evalTime2));
    }

    #[Test]
    public function it_evaluates_sla_breached_when_time_reaches_or_exceeds_due_date(): void
    {
        $dueAt = Carbon::parse('2026-11-05 18:00:00');
        $warningAt = Carbon::parse('2026-11-05 15:00:00');

        $ticket = Ticket::factory()->make([
            'status' => TicketStatus::IN_PROGRESS,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
        ]);

        // Exactly at due date
        $evalTime1 = Carbon::parse('2026-11-05 18:00:00');
        $this->assertSame('sla_breached', $ticket->getSlaState($evalTime1));

        // Past due date
        $evalTime2 = Carbon::parse('2026-11-05 19:00:00');
        $this->assertSame('sla_breached', $ticket->getSlaState($evalTime2));
    }

    #[Test]
    public function resolved_ticket_freezes_state_based_on_resolved_timestamp(): void
    {
        $dueAt = Carbon::parse('2026-11-05 18:00:00');
        $warningAt = Carbon::parse('2026-11-05 15:00:00');

        // Case 1: Resolved within SLA
        $ticketResolvedInTime = Ticket::factory()->make([
            'status' => TicketStatus::RESOLVED,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
            'resolved_at' => Carbon::parse('2026-11-05 14:00:00'),
        ]);

        // Even if evaluated days later, it was resolved in time -> within_sla
        $laterTime = Carbon::parse('2026-11-10 10:00:00');
        $this->assertSame('within_sla', $ticketResolvedInTime->getSlaState($laterTime));

        // Case 2: Resolved after SLA breached
        $ticketResolvedLate = Ticket::factory()->make([
            'status' => TicketStatus::RESOLVED,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
            'resolved_at' => Carbon::parse('2026-11-05 19:00:00'),
        ]);

        $this->assertSame('sla_breached', $ticketResolvedLate->getSlaState($laterTime));
    }
}
