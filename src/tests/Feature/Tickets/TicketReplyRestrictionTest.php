<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketReplyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function agent_a_cannot_reply_or_note_on_ticket_assigned_to_agent_b(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A']);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B']);
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Agent A attempts public reply -> 403 Forbidden
        $responseReply = $this->actingAs($agentA)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Agent A trying to reply to Agent B ticket',
                'is_internal' => false,
            ]);
        $responseReply->assertForbidden();

        // Agent A attempts internal note -> 403 Forbidden
        $responseNote = $this->actingAs($agentA)
            ->post(route('ticket_messages.internal_note', $ticket), [
                'body' => 'Agent A trying to add internal note to Agent B ticket',
            ]);
        $responseNote->assertForbidden();

        // Agent B (assigned) succeeds
        $responseAssigned = $this->actingAs($agentB)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Agent B official reply',
                'is_internal' => false,
            ]);
        $responseAssigned->assertCreated();
    }

    #[Test]
    public function no_agent_can_reply_to_an_unassigned_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $unassignedTicket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        // Agent public reply on unassigned ticket -> 403
        $this->actingAs($agent)
            ->post(route('ticket_messages.store', $unassignedTicket), [
                'body' => 'Agent attempting public reply to unassigned ticket',
            ])
            ->assertForbidden();

        // Agent internal note on unassigned ticket -> 403
        $this->actingAs($agent)
            ->post(route('ticket_messages.internal_note', $unassignedTicket), [
                'body' => 'Agent attempting internal note to unassigned ticket',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function admins_can_reply_and_add_notes_to_unassigned_or_assigned_tickets(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $unassignedTicket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $assignedTicket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Admin reply to unassigned
        $this->actingAs($admin)
            ->post(route('ticket_messages.store', $unassignedTicket), [
                'body' => 'Admin public reply on unassigned ticket',
            ])
            ->assertCreated();

        // Admin internal note to unassigned
        $this->actingAs($admin)
            ->post(route('ticket_messages.internal_note', $unassignedTicket), [
                'body' => 'Admin internal note on unassigned ticket',
            ])
            ->assertCreated();

        // Admin reply to assigned
        $this->actingAs($admin)
            ->post(route('ticket_messages.store', $assignedTicket), [
                'body' => 'Admin public reply on assigned ticket',
            ])
            ->assertCreated();

        // Admin internal note to assigned
        $this->actingAs($admin)
            ->post(route('ticket_messages.internal_note', $assignedTicket), [
                'body' => 'Admin internal note on assigned ticket',
            ])
            ->assertCreated();
    }

    #[Test]
    public function customers_never_receive_internal_notes_in_payload_responses(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Confidential staff assessment notes.',
            'is_internal' => true,
        ]);

        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Hello customer, we are investigating your issue.',
            'is_internal' => false,
        ]);

        // JSON response test
        $responseJson = $this->actingAs($customer)->getJson(route('tickets.show', $ticket));
        $responseJson->assertOk();

        $messages = $responseJson->json('ticket.messages');
        $this->assertCount(1, $messages);
        $this->assertSame('Hello customer, we are investigating your issue.', $messages[0]['body']);
        $this->assertFalse((bool) $messages[0]['is_internal']);

        // HTML response test
        $responseHtml = $this->actingAs($customer)->get(route('tickets.show', $ticket));
        $responseHtml->assertOk();
        $responseHtml->assertSee('Hello customer, we are investigating your issue.');
        $responseHtml->assertDontSee('Confidential staff assessment notes.');
    }
}
