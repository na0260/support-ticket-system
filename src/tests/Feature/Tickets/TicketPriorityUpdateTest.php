<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Sla\BusinessHoursCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketPriorityUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_owner_can_update_priority(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Alice Customer']);
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'priority' => TicketPriority::LOW,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($customer)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame(TicketPriority::HIGH, $ticket->priority);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $customer->id,
            'event' => 'priority_changed',
        ]);

        $activity = TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('event', 'priority_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('low', $activity->properties['old_priority']);
        $this->assertSame('high', $activity->properties['new_priority']);
        $this->assertSame('Priority changed from Low to High by Alice Customer (Customer)', $activity->properties['description']);
    }

    #[Test]
    public function admin_can_update_priority(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Bob Admin']);
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'priority' => TicketPriority::MEDIUM,
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::CRITICAL->value,
            ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame(TicketPriority::CRITICAL, $ticket->priority);

        $activity = TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('event', 'priority_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('Priority changed from Medium to Critical by Bob Admin (Admin)', $activity->properties['description']);
    }

    #[Test]
    public function assigned_agent_can_update_priority(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Charlie Agent']);
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'priority' => TicketPriority::LOW,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $response = $this->actingAs($agent)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::MEDIUM->value,
            ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame(TicketPriority::MEDIUM, $ticket->priority);

        $activity = TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('event', 'priority_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('Priority changed from Low to Medium by Charlie Agent (Agent)', $activity->properties['description']);
    }

    #[Test]
    public function unauthorized_agent_cannot_update_priority(): void
    {
        $assignedAgent = User::factory()->agent()->create();
        $unauthorizedAgent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $assignedAgent->id,
            'priority' => TicketPriority::LOW,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Dedicated endpoint
        $responseDedicated = $this->actingAs($unauthorizedAgent)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ]);
        $responseDedicated->assertForbidden();

        // General update endpoint
        $responseGeneral = $this->actingAs($unauthorizedAgent)
            ->patchJson(route('tickets.update', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ]);
        $responseGeneral->assertForbidden();

        $ticket->refresh();
        $this->assertSame(TicketPriority::LOW, $ticket->priority);
    }

    #[Test]
    public function non_owner_customer_cannot_update_priority(): void
    {
        $owner = User::factory()->customer()->create();
        $attacker = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($owner, 'creator')->create([
            'priority' => TicketPriority::LOW,
        ]);

        $response = $this->actingAs($attacker)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ]);

        $response->assertForbidden();
        $ticket->refresh();
        $this->assertSame(TicketPriority::LOW, $ticket->priority);
    }

    #[Test]
    public function priority_change_from_medium_to_high_recalculates_sla_due_at(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00'); // Sunday 09:00 (workday start)

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'priority' => TicketPriority::MEDIUM, // 24 business hours = 2 days 6 hours (at 9h/day)
            'created_at' => Carbon::now(),
            'status' => TicketStatus::OPEN,
        ]);

        $mediumDueAt = $ticket->sla_due_at->clone();

        // Change priority to HIGH (8 business hours)
        $response = $this->actingAs($admin)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ]);

        $response->assertOk();
        $ticket->refresh();

        $this->assertSame(TicketPriority::HIGH, $ticket->priority);

        // Due date must be recalculation for HIGH priority:
        // Sunday 09:00 + 8 business hours = Sunday 17:00
        $expectedDueAt = app(BusinessHoursCalculator::class)->calculateDueAt(
            $ticket->created_at,
            TicketPriority::HIGH,
        );

        $this->assertSame($expectedDueAt->toIso8601String(), $ticket->sla_due_at->toIso8601String());
        $this->assertTrue($ticket->sla_due_at->isBefore($mediumDueAt));

        Carbon::setTestNow();
    }

    #[Test]
    public function changing_priority_updates_sla_breach_status_flags(): void
    {
        // Ticket created 10 business hours ago
        // Under MEDIUM priority (24 business hours), 10 hours is within SLA
        // Under HIGH priority (8 business hours), 10 hours is BREACHED!
        Carbon::setTestNow('2026-11-01 09:00:00'); // Sunday 09:00

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'priority' => TicketPriority::MEDIUM,
            'created_at' => Carbon::now(),
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Advance time to Monday 10:00 (9 hours on Sunday + 1 hour on Monday = 10 business hours elapsed)
        Carbon::setTestNow('2026-11-02 10:00:00');

        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);

        // Update priority to HIGH (8 hours window). Since 10 hours elapsed, it is now breached!
        $this->actingAs($admin)
            ->patchJson(route('tickets.update_priority', $ticket), [
                'priority' => TicketPriority::HIGH->value,
            ])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame(TicketPriority::HIGH, $ticket->priority);
        $this->assertSame(SlaStatus::BREACHED, $ticket->sla_status);
        $this->assertSame('sla_breached', $ticket->sla_state);

        Carbon::setTestNow();
    }
}
