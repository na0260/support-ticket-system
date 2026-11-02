<?php

declare(strict_types=1);

namespace Tests\Feature\Sla;

use App\Enums\SlaStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingBreachNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Sla\SlaEscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SlaEscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_escalates_active_ticket_to_approaching_breach_when_past_warning_date(): void
    {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'sla_warning_at' => now()->subHour(),
            'sla_due_at' => now()->addHour(),
            'resolved_at' => null,
        ]);

        $service = app(SlaEscalationService::class);
        $escalatedCount = $service->escalateApproachingBreach();

        $this->assertSame(1, $escalatedCount);

        $ticket->refresh();
        $this->assertSame(SlaStatus::APPROACHING_BREACH, $ticket->sla_status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'event' => 'sla_approaching_breach',
        ]);

        Notification::assertSentTo($agent, SlaApproachingBreachNotification::class);
    }

    #[Test]
    public function it_escalates_active_ticket_to_breached_and_sets_escalated_at_when_past_due_date(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'sla_warning_at' => now()->subHours(5),
            'sla_due_at' => now()->subHour(),
            'escalated_at' => null,
            'resolved_at' => null,
        ]);

        $service = app(SlaEscalationService::class);
        $breachedCount = $service->escalateBreached();

        $this->assertSame(1, $breachedCount);

        $ticket->refresh();
        $this->assertSame(SlaStatus::BREACHED, $ticket->sla_status);
        $this->assertNotNull($ticket->escalated_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'event' => 'ticket_escalated',
        ]);

        Notification::assertSentTo($agent, SlaBreachedNotification::class);
        Notification::assertSentTo($admin, SlaBreachedNotification::class);
    }

    #[Test]
    public function resolved_or_closed_tickets_are_not_escalated(): void
    {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        $resolvedTicket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::RESOLVED,
            'resolved_at' => now()->subHours(2),
            'sla_warning_at' => now()->subHours(3),
            'sla_due_at' => now()->subHour(),
        ]);

        $closedTicket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::CLOSED,
            'closed_at' => now()->subHours(4),
            'sla_warning_at' => now()->subHours(5),
            'sla_due_at' => now()->subHour(),
        ]);

        $service = app(SlaEscalationService::class);

        $approachingCount = $service->escalateApproachingBreach();
        $breachedCount = $service->escalateBreached();

        $this->assertSame(0, $approachingCount);
        $this->assertSame(0, $breachedCount);

        Notification::assertNothingSent();
    }

    #[Test]
    public function escalation_is_strictly_idempotent(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();

        // Ticket 1: Approaching breach
        $ticket1 = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'sla_warning_at' => now()->subHours(2),
            'sla_due_at' => now()->addHour(),
            'resolved_at' => null,
        ]);

        // Ticket 2: Breached
        $ticket2 = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'sla_warning_at' => now()->subHours(5),
            'sla_due_at' => now()->subMinute(),
            'resolved_at' => null,
        ]);

        $service = app(SlaEscalationService::class);

        // Run 1: escalates approaching breach (1) and breached (1)
        $approachingFirst = $service->escalateApproachingBreach();
        $breachedFirst = $service->escalateBreached();

        $this->assertSame(1, $approachingFirst);
        $this->assertSame(1, $breachedFirst);
        $this->assertDatabaseCount('ticket_activities', 2);

        // Run 2: immediate subsequent run produces 0 changes and 0 new activities
        $approachingSecondRun = $service->escalateApproachingBreach();
        $breachedSecondRun = $service->escalateBreached();

        $this->assertSame(0, $approachingSecondRun);
        $this->assertSame(0, $breachedSecondRun);

        $this->assertDatabaseCount('ticket_activities', 2);
    }
}
