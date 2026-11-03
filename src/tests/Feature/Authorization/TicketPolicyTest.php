<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_cannot_view_other_customers_tickets(): void
    {
        $owner = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($owner, 'creator')->create();

        $response = $this->actingAs($otherCustomer)->get(route('tickets.show', $ticket));

        $response->assertForbidden();
    }

    #[Test]
    public function customer_can_view_own_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $response = $this->actingAs($customer)->get(route('tickets.show', $ticket));

        $response->assertOk();
    }

    #[Test]
    public function agent_and_admin_can_view_any_customers_ticket(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
    }

    #[Test]
    public function customer_cannot_assign_or_claim_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $this->actingAs($customer)
            ->post(route('tickets.assign', $ticket))
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('tickets.claim', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function assigned_agent_and_admin_can_assign_or_reassign_tickets(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $ticket = Ticket::factory()->create(['assigned_agent_id' => $agentA->id, 'status' => TicketStatus::IN_PROGRESS]);

        // Assigned Agent A can reassign to Agent B
        $this->actingAs($agentA)->post(route('tickets.assign', $ticket), ['assigned_agent_id' => $agentB->id])->assertOk();

        // Admin can reassign
        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['assigned_agent_id' => $agentA->id])->assertOk();

        // Other Agent B cannot reassign when ticket is assigned to Agent A
        $ticket->refresh();
        $ticket->assigned_agent_id = $agentA->id;
        $ticket->save();
        $this->actingAs($agentB)->post(route('tickets.assign', $ticket), ['assigned_agent_id' => $agentB->id])->assertForbidden();
    }

    #[Test]
    public function agent_and_admin_can_claim_an_unassigned_active_ticket(): void
    {
        $ticket1 = Ticket::factory()->create(['assigned_agent_id' => null, 'status' => TicketStatus::OPEN]);
        $ticket2 = Ticket::factory()->create(['assigned_agent_id' => null, 'status' => TicketStatus::OPEN]);
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->post(route('tickets.claim', $ticket1))->assertOk();
        $this->actingAs($admin)->post(route('tickets.claim', $ticket2))->assertOk();
    }

    #[Test]
    public function cannot_claim_already_assigned_or_terminal_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $assignedTicket = Ticket::factory()->assignedTo($agent)->create();
        $closedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::CLOSED,
        ]);

        $this->actingAs($agent)->post(route('tickets.claim', $assignedTicket))->assertForbidden();
        $this->actingAs($agent)->post(route('tickets.claim', $closedTicket))->assertForbidden();
    }

    #[Test]
    public function customer_cannot_delete_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $this->actingAs($customer)
            ->delete(route('tickets.destroy', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function agent_cannot_delete_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($agent)
            ->delete(route('tickets.destroy', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_delete_tickets(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)
            ->delete(route('tickets.destroy', $ticket))
            ->assertOk();
    }

    #[Test]
    public function customer_can_close_their_own_ticket_but_not_others(): void
    {
        $customer = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $this->actingAs($customer)->post(route('tickets.close', $ticket))->assertOk();
        $this->actingAs($otherCustomer)->post(route('tickets.close', $ticket))->assertForbidden();
    }

    #[Test]
    public function assigned_agent_and_admin_can_resolve_and_close_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $assignedTicket = Ticket::factory()->create([
            'status' => TicketStatus::IN_PROGRESS,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('tickets.resolve', $assignedTicket))->assertOk();

        // Admin can resolve unassigned tickets
        $unassignedTicket = Ticket::factory()->create([
            'status' => TicketStatus::OPEN,
            'assigned_agent_id' => null,
        ]);
        $this->actingAs($admin)->post(route('tickets.resolve', $unassignedTicket))->assertOk();

        // Assigned agent and admin can close
        $this->actingAs($agent)->post(route('tickets.close', $assignedTicket))->assertOk();
        $this->actingAs($admin)->post(route('tickets.close', $unassignedTicket))->assertOk();
    }

    #[Test]
    public function unassigned_agent_cannot_resolve_or_close_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $unassignedTicket = Ticket::factory()->create([
            'status' => TicketStatus::OPEN,
            'assigned_agent_id' => null,
        ]);

        $this->actingAs($agent)->post(route('tickets.resolve', $unassignedTicket))->assertForbidden();
        $this->actingAs($agent)->post(route('tickets.close', $unassignedTicket))->assertForbidden();
    }

    #[Test]
    public function peer_agent_cannot_resolve_or_close_someone_elses_ticket(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();

        $ticketB = Ticket::factory()->create([
            'status' => TicketStatus::IN_PROGRESS,
            'assigned_agent_id' => $agentB->id,
        ]);

        $this->actingAs($agentA)->post(route('tickets.resolve', $ticketB))->assertForbidden();
        $this->actingAs($agentA)->post(route('tickets.close', $ticketB))->assertForbidden();
    }

    #[Test]
    public function customer_cannot_resolve_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $this->actingAs($customer)
            ->post(route('tickets.resolve', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function customer_can_update_only_while_open(): void
    {
        $customer = User::factory()->customer()->create();
        $openTicket = Ticket::factory()->for($customer, 'creator')->create(['status' => TicketStatus::OPEN]);
        $inProgressTicket = Ticket::factory()->for($customer, 'creator')->create(['status' => TicketStatus::IN_PROGRESS]);

        $this->actingAs($customer)->patch(route('tickets.update', $openTicket))->assertOk();
        $this->actingAs($customer)->patch(route('tickets.update', $inProgressTicket))->assertForbidden();
    }
}
