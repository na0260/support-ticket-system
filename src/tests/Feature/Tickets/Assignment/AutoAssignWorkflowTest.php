<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets\Assignment;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AutoAssignWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function auto_assign_endpoint_assigns_ticket_and_transitions_status(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create(['is_available' => true]);

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.auto_assign', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $admin->id,
            'event' => 'ticket_auto_assigned',
        ]);
    }

    #[Test]
    public function auto_assign_endpoint_returns_json_when_requested(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create(['is_available' => true]);

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('tickets.auto_assign', $ticket));

        $response->assertOk();
        $response->assertJsonPath('assigned_agent.id', $agent->id);
        $response->assertJsonPath('message', 'Ticket automatically assigned successfully.');

        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
    }

    #[Test]
    public function auto_assign_handles_zero_available_agents_gracefully(): void
    {
        $admin = User::factory()->admin()->create();
        // Agent is unavailable
        User::factory()->agent()->create(['is_available' => false]);

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.auto_assign', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('status', 'No available agents found for automatic assignment.');

        $ticket->refresh();
        $this->assertNull($ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::OPEN, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $admin->id,
            'event' => 'ticket_auto_assignment_failed',
        ]);
    }

    #[Test]
    public function ticket_is_automatically_assigned_on_creation_when_configured(): void
    {
        Config::set('tickets.assignment.auto_assign_on_creation', true);

        $agent = User::factory()->agent()->create(['is_available' => true]);
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::HIGH->value,
                'subject' => 'Urgent database sync latency issue',
                'description' => 'Replication lag between master and slave databases has spiked to 300 seconds.',
            ]);

        $ticket = Ticket::query()->where('subject', 'Urgent database sync latency issue')->first();
        $this->assertNotNull($ticket);

        $response->assertRedirect(route('tickets.show', $ticket));

        // Ticket should be automatically assigned to the available agent
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'event' => 'ticket_auto_assigned',
        ]);
    }

    #[Test]
    public function agent_cannot_trigger_manual_auto_assign(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($agent)
            ->post(route('tickets.auto_assign', $ticket));

        $response->assertForbidden();
    }

    #[Test]
    public function customer_cannot_trigger_auto_assign(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($customer)
            ->post(route('tickets.auto_assign', $ticket));

        $response->assertForbidden();
    }
}
