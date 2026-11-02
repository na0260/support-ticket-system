<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SlaStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
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
        Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'sla_warning_at' => now()->subMinutes(10),
            'sla_due_at' => now()->addMinutes(30),
            'resolved_at' => null,
        ]);

        // 1 breached ticket
        Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'sla_warning_at' => now()->subHours(2),
            'sla_due_at' => now()->subMinute(),
            'resolved_at' => null,
        ]);

        $this->artisan('tickets:check-sla')
            ->expectsOutputToContain('SLA Escalation Check: 1 tickets approaching breach, 1 tickets breached.')
            ->assertSuccessful();
    }
}
