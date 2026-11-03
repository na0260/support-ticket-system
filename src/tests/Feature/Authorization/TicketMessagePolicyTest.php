<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketMessagePolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_attempting_to_view_an_internal_note_is_forbidden(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();
        $internalNote = TicketMessage::factory()
            ->for($ticket)
            ->internal()
            ->create();

        // Must return 403 Forbidden even though the customer owns the parent ticket.
        $this->actingAs($customer)
            ->get(route('ticket_messages.show', $internalNote))
            ->assertForbidden();
    }

    #[Test]
    public function customer_attempting_to_post_an_internal_note_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $this->actingAs($customer)
            ->post(route('ticket_messages.internal_note', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function assigned_agent_and_admin_can_create_and_view_internal_notes(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();
        $internalNote = TicketMessage::factory()
            ->for($ticket)
            ->internal()
            ->create();

        $admin = User::factory()->admin()->create();

        // Assigned agent can view and create
        $this->actingAs($agent)
            ->get(route('ticket_messages.show', $internalNote))
            ->assertOk();

        $this->actingAs($agent)
            ->post(route('ticket_messages.internal_note', $ticket), [
                'body' => 'Assigned agent internal note.',
            ])
            ->assertCreated();

        // Admin can view and create
        $this->actingAs($admin)
            ->get(route('ticket_messages.show', $internalNote))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('ticket_messages.internal_note', $ticket), [
                'body' => 'Admin internal note.',
            ])
            ->assertCreated();
    }

    #[Test]
    public function agent_cannot_reply_or_post_internal_note_to_unassigned_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $unassignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        // Public reply rejected with 403
        $this->actingAs($agent)
            ->post(route('ticket_messages.store', $unassignedTicket), [
                'body' => 'Attempting reply on unassigned ticket',
            ])
            ->assertForbidden();

        // Internal note rejected with 403
        $this->actingAs($agent)
            ->post(route('ticket_messages.internal_note', $unassignedTicket), [
                'body' => 'Attempting internal note on unassigned ticket',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function agent_cannot_reply_to_ticket_assigned_to_another_agent(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();

        $ticket = Ticket::factory()->assignedTo($agentA)->create();

        // Agent B cannot post public reply to Agent A's ticket
        $this->actingAs($agentB)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Agent B attempting reply to Agent A ticket',
            ])
            ->assertForbidden();

        // Agent B cannot post internal note to Agent A's ticket
        $this->actingAs($agentB)
            ->post(route('ticket_messages.internal_note', $ticket), [
                'body' => 'Agent B attempting internal note to Agent A ticket',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_reply_and_post_internal_notes_to_any_ticket(): void
    {
        $admin = User::factory()->admin()->create();
        $unassignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        // Admin can reply to unassigned ticket
        $this->actingAs($admin)
            ->post(route('ticket_messages.store', $unassignedTicket), [
                'body' => 'Admin public reply to unassigned ticket.',
            ])
            ->assertCreated();

        // Admin can post internal note to unassigned ticket
        $this->actingAs($admin)
            ->post(route('ticket_messages.internal_note', $unassignedTicket), [
                'body' => 'Admin internal note on unassigned ticket.',
            ])
            ->assertCreated();
    }

    #[Test]
    public function customer_cannot_reply_to_closed_ticket_but_assigned_agent_and_admin_can(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $closedTicket = Ticket::factory()
            ->for($customer, 'creator')
            ->create(['status' => TicketStatus::CLOSED, 'assigned_agent_id' => $agent->id]);

        // Customer rejected (403)
        $this->actingAs($customer)
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Customer reply to closed ticket',
            ])
            ->assertForbidden();

        // Assigned Agent can reply
        $this->actingAs($agent)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Agent reply to closed ticket',
            ])
            ->assertRedirect(route('tickets.show', $closedTicket));

        // Admin can reply
        $this->actingAs($admin)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Admin reply to closed ticket',
            ])
            ->assertRedirect(route('tickets.show', $closedTicket));

        // Assigned Agent can add internal note on closed ticket
        $this->actingAs($agent)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.internal_note', $closedTicket), [
                'body' => 'Post-closure audit note',
            ])
            ->assertRedirect(route('tickets.show', $closedTicket));
    }

    #[Test]
    public function customer_can_view_public_reply_on_own_ticket_but_not_others(): void
    {
        $owner = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($owner, 'creator')->create();
        $publicMessage = TicketMessage::factory()->for($ticket)->create(['is_internal' => false]);

        $this->actingAs($owner)
            ->get(route('ticket_messages.show', $publicMessage))
            ->assertOk();

        $this->actingAs($otherCustomer)
            ->get(route('ticket_messages.show', $publicMessage))
            ->assertForbidden();
    }

    #[Test]
    public function customer_can_post_reply_to_own_open_ticket_but_not_others(): void
    {
        $owner = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($owner, 'creator')->create(['status' => TicketStatus::OPEN]);

        $this->actingAs($owner)
            ->post(route('ticket_messages.store', $ticket))
            ->assertCreated();

        $this->actingAs($otherCustomer)
            ->post(route('ticket_messages.store', $ticket))
            ->assertForbidden();
    }
}
