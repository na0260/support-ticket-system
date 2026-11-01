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
    public function agent_and_admin_can_assign_tickets(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->post(route('tickets.assign', $ticket))->assertOk();
        $this->actingAs($admin)->post(route('tickets.assign', $ticket))->assertOk();
    }

    #[Test]
    public function agent_and_admin_can_claim_an_unassigned_active_ticket(): void
    {
        $ticket = Ticket::factory()->create(['assigned_agent_id' => null, 'status' => TicketStatus::OPEN]);
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->post(route('tickets.claim', $ticket))->assertOk();
        $this->actingAs($admin)->post(route('tickets.claim', $ticket))->assertOk();
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
    public function agent_and_admin_can_resolve_and_close_tickets(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::OPEN]);
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->post(route('tickets.resolve', $ticket))->assertOk();
        $this->actingAs($admin)->post(route('tickets.resolve', $ticket))->assertOk();

        $this->actingAs($agent)->post(route('tickets.close', $ticket))->assertOk();
        $this->actingAs($admin)->post(route('tickets.close', $ticket))->assertOk();
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
