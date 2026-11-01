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
    public function agent_and_admin_can_create_and_view_internal_notes(): void
    {
        $ticket = Ticket::factory()->create();
        $internalNote = TicketMessage::factory()
            ->for($ticket)
            ->internal()
            ->create();

        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        // Agent can view and create
        $this->actingAs($agent)
            ->get(route('ticket_messages.show', $internalNote))
            ->assertOk();

        $this->actingAs($agent)
            ->post(route('ticket_messages.internal_note', $ticket))
            ->assertCreated();

        // Admin can view and create
        $this->actingAs($admin)
            ->get(route('ticket_messages.show', $internalNote))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('ticket_messages.internal_note', $ticket))
            ->assertCreated();
    }

    #[Test]
    public function no_user_can_add_replies_to_a_closed_ticket(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $closedTicket = Ticket::factory()
            ->for($customer, 'creator')
            ->create(['status' => TicketStatus::CLOSED]);

        // Customer rejected
        $this->actingAs($customer)
            ->post(route('ticket_messages.store', $closedTicket))
            ->assertForbidden();

        // Agent rejected
        $this->actingAs($agent)
            ->post(route('ticket_messages.store', $closedTicket))
            ->assertForbidden();

        // Admin rejected (explicit policy return false on terminal status)
        $this->actingAs($admin)
            ->post(route('ticket_messages.store', $closedTicket))
            ->assertForbidden();

        // Internal notes on closed tickets are also rejected
        $this->actingAs($agent)
            ->post(route('ticket_messages.internal_note', $closedTicket))
            ->assertForbidden();
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
