<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SlaStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CheckSlaEscalationsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_sla_escalation_checks_and_exits_successfully(): void
    {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        // 1 approaching breach ticket
        $approachingTicket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'is_escalated' => false,
            'sla_warning_at' => now()->subMinutes(10),
            'sla_due_at' => now()->addMinutes(30),
            'resolved_at' => null,
        ]);

        // 1 breached ticket
        $breachedTicket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'is_escalated' => false,
            'sla_warning_at' => now()->subHours(2),
            'sla_due_at' => now()->subMinute(),
            'resolved_at' => null,
        ]);

        $this->artisan('sla:check-escalations')
            ->expectsOutputToContain('SLA Escalation Check: 1 tickets approaching breach, 1 tickets breached.')
            ->assertSuccessful();

        $approachingTicket->refresh();
        $this->assertTrue($approachingTicket->is_escalated);
        $this->assertSame(SlaStatus::APPROACHING_BREACH, $approachingTicket->sla_status);

        $breachedTicket->refresh();
        $this->assertTrue($breachedTicket->is_escalated);
        $this->assertSame(SlaStatus::BREACHED, $breachedTicket->sla_status);
        $this->assertNotNull($breachedTicket->escalated_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $approachingTicket->id,
            'event' => 'sla_approaching_breach',
        ]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $breachedTicket->id,
            'event' => 'ticket_escalated',
        ]);

        $approachingActivity = TicketActivity::where('ticket_id', $approachingTicket->id)->latest('id')->first();
        $this->assertStringContainsString('approaching breach', (string) ($approachingActivity?->properties['description'] ?? ''));

        $breachedActivity = TicketActivity::where('ticket_id', $breachedTicket->id)->latest('id')->first();
        $this->assertStringContainsString('breach', (string) ($breachedActivity?->properties['description'] ?? ''));
    }

    #[Test]
    public function it_runs_via_tickets_check_sla_alias(): void
    {
        Notification::fake();

        $this->artisan('tickets:check-sla')
            ->expectsOutputToContain('SLA Escalation Check: 0 tickets approaching breach, 0 tickets breached.')
            ->assertSuccessful();
    }
}
