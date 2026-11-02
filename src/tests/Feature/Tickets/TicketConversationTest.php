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

final class TicketConversationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_can_post_public_reply_on_ticket(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($customer)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Here is the additional error log you requested.',
                'is_internal' => false,
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'Here is the additional error log you requested.',
            'is_internal' => false,
        ]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $customer->id,
            'event' => 'customer_replied',
        ]);
    }

    #[Test]
    public function agent_public_reply_transitions_status_to_waiting_customer(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Could you please verify if clearing your browser cookies resolves the issue?',
                'is_internal' => false,
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::WAITING_CUSTOMER, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $agent->id,
            'event' => 'agent_replied',
        ]);
    }

    #[Test]
    public function staff_can_post_internal_note_without_changing_ticket_status(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Customer account is flagged for pending payment verification.',
                'is_internal' => true,
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Customer account is flagged for pending payment verification.',
            'is_internal' => true,
        ]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $agent->id,
            'event' => 'internal_note_added',
        ]);
    }

    #[Test]
    public function customer_cannot_view_internal_notes_in_html_or_json(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Super secret staff note about billing discrepancy.',
            'is_internal' => true,
        ]);

        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Public agent response to customer.',
            'is_internal' => false,
        ]);

        // Customer view: internal note is hidden
        $responseCustomer = $this->actingAs($customer)->get(route('tickets.show', $ticket));
        $responseCustomer->assertOk();
        $responseCustomer->assertSee('Public agent response to customer.');
        $responseCustomer->assertDontSee('Super secret staff note about billing discrepancy.');

        // Customer JSON request: internal notes excluded from messages collection
        $responseCustomerJson = $this->actingAs($customer)->getJson(route('tickets.show', $ticket));
        $responseCustomerJson->assertOk();
        $messages = $responseCustomerJson->json('ticket.messages');
        $this->assertCount(1, $messages);
        $this->assertSame('Public agent response to customer.', $messages[0]['body']);

        // Agent view: internal note is fully visible with label
        $responseAgent = $this->actingAs($agent)->get(route('tickets.show', $ticket));
        $responseAgent->assertOk();
        $responseAgent->assertSee('Super secret staff note about billing discrepancy.');
        $responseAgent->assertSee('[INTERNAL NOTE - HIDDEN FROM CUSTOMER]');
    }

    #[Test]
    public function customer_cannot_create_internal_notes(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        // 1. Calling dedicated internal note route gives 403
        $responseDedicated = $this->actingAs($customer)
            ->post(route('ticket_messages.internal_note', $ticket), [
                'body' => 'Attempting customer internal note via dedicated endpoint',
            ]);
        $responseDedicated->assertForbidden();

        // 2. Calling general store route with is_internal=true forces is_internal to false
        $responseGeneral = $this->actingAs($customer)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Attempting customer internal note via store',
                'is_internal' => true,
            ]);

        $responseGeneral->assertRedirect(route('tickets.show', $ticket));

        $msg = TicketMessage::query()->where('body', 'Attempting customer internal note via store')->first();
        $this->assertNotNull($msg);
        $this->assertFalse($msg->is_internal);
    }
}
