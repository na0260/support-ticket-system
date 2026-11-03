<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ticket_is_automatically_assigned_to_agent_with_lowest_workload_on_creation(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Busy Agent A', 'is_available' => true]);
        $agentB = User::factory()->agent()->create(['name' => 'Free Agent B', 'is_available' => true]);
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        // Agent A has 2 active tickets
        Ticket::factory()->count(2)->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Customer creates ticket
        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::HIGH->value,
                'subject' => 'High priority system failure',
                'description' => 'System is not responding to API calls for checkout.',
            ]);

        $ticket = Ticket::query()->where('subject', 'High priority system failure')->first();
        $this->assertNotNull($ticket);
        $response->assertRedirect(route('tickets.show', $ticket));

        // Auto-assigned to Agent B who has 0 active tickets
        $this->assertSame($agentB->id, $ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'event' => 'ticket_auto_assigned',
        ]);
    }

    #[Test]
    public function ticket_remains_unassigned_in_open_status_when_no_agents_are_available(): void
    {
        // Only unavailable agent exists
        User::factory()->agent()->create(['is_available' => false]);
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::MEDIUM->value,
                'subject' => 'Ticket created without available agents',
                'description' => 'Detailed description of the issue that needs triage.',
            ]);

        $ticket = Ticket::query()->where('subject', 'Ticket created without available agents')->first();
        $this->assertNotNull($ticket);
        $response->assertRedirect(route('tickets.show', $ticket));

        // Remains unassigned and in open status
        $this->assertNull($ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::OPEN, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'event' => 'ticket_auto_assignment_failed',
        ]);
    }

    #[Test]
    public function agent_visibility_isolation_restricts_tickets_to_unassigned_and_self_assigned(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent Alpha']);
        $agentB = User::factory()->agent()->create(['name' => 'Agent Beta']);
        $customer = User::factory()->customer()->create(['name' => 'Customer Charlie']);

        $ticket1 = Ticket::factory()->create([
            'subject' => 'Ticket 1 - Assigned to Alpha',
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
            'created_by' => $customer->id,
        ]);

        $ticket2 = Ticket::factory()->create([
            'subject' => 'Ticket 2 - Assigned to Beta',
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::IN_PROGRESS,
            'created_by' => $customer->id,
        ]);

        $ticket3 = Ticket::factory()->create([
            'subject' => 'Ticket 3 - Unassigned Active',
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
            'created_by' => $customer->id,
        ]);

        // Authenticate as Agent A
        $this->actingAs($agentA);

        // 1. Index listing: Ticket 1 and Ticket 3 are visible; Ticket 2 is hidden
        $indexResponse = $this->get(route('tickets.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee($ticket1->ticket_number);
        $indexResponse->assertSee($ticket3->ticket_number);
        $indexResponse->assertDontSee($ticket2->ticket_number);

        // 2. Direct show access:
        $this->get(route('tickets.show', $ticket1))->assertOk();
        $this->get(route('tickets.show', $ticket3))->assertOk();
        $this->get(route('tickets.show', $ticket2))->assertForbidden();

        // 3. Search endpoint also isolates Ticket 2
        $searchResponse = $this->getJson(route('tickets.index', ['keyword' => 'Assigned to Beta']));
        $searchResponse->assertOk();
        $this->assertCount(0, $searchResponse->json('tickets.data'));
    }

    #[Test]
    public function assignment_and_reassignment_permissions_are_strictly_enforced(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A']);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B']);
        $admin = User::factory()->admin()->create(['name' => 'Admin Boss']);
        $customer = User::factory()->customer()->create();

        $ticket1 = Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
            'created_by' => $customer->id,
        ]);

        $ticket3 = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
            'created_by' => $customer->id,
        ]);

        // 1. Agent cannot trigger manual auto-assignment (403)
        $this->actingAs($agentA)
            ->post(route('tickets.auto_assign', $ticket3))
            ->assertForbidden();

        // Admin CAN trigger auto-assign on a dedicated unassigned ticket
        $autoTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
            'created_by' => $customer->id,
        ]);

        $this->actingAs($admin)
            ->from(route('tickets.show', $autoTicket))
            ->post(route('tickets.auto_assign', $autoTicket))
            ->assertRedirect(route('tickets.show', $autoTicket));

        // 2. Agent A can claim unassigned Ticket 3
        $this->actingAs($agentA)
            ->from(route('tickets.show', $ticket3))
            ->post(route('tickets.claim', $ticket3))
            ->assertRedirect(route('tickets.show', $ticket3));

        $ticket3->refresh();
        $this->assertSame($agentA->id, $ticket3->assigned_agent_id);

        // 3. Agent A can reassign Ticket 1 to Agent B
        $this->actingAs($agentA)
            ->from(route('tickets.show', $ticket1))
            ->post(route('tickets.assign', $ticket1), [
                'assigned_agent_id' => $agentB->id,
            ])
            ->assertRedirect(route('tickets.show', $ticket1));

        $ticket1->refresh();
        $this->assertSame($agentB->id, $ticket1->assigned_agent_id);

        // 4. Agent A (now not assigned to Ticket 1) cannot reassign Ticket 1 (403)
        $this->actingAs($agentA)
            ->post(route('tickets.assign', $ticket1), [
                'assigned_agent_id' => $agentA->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function dynamic_status_transitions_on_customer_and_agent_replies(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Support Agent']);
        $customer = User::factory()->customer()->create(['name' => 'Customer']);

        // 1. Customer reply to resolved ticket transitions status to in_progress
        $resolvedTicket = Ticket::factory()->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::RESOLVED,
            'resolved_at' => now()->subDay(),
            'created_by' => $customer->id,
        ]);

        $this->actingAs($customer)
            ->from(route('tickets.show', $resolvedTicket))
            ->post(route('ticket_messages.store', $resolvedTicket), [
                'body' => 'I still observe this issue after your fix.',
                'is_internal' => false,
            ])
            ->assertRedirect(route('tickets.show', $resolvedTicket));

        $resolvedTicket->refresh();
        $this->assertSame(TicketStatus::IN_PROGRESS, $resolvedTicket->status);
        $this->assertNull($resolvedTicket->resolved_at);

        $activity = TicketActivity::query()
            ->where('ticket_id', $resolvedTicket->id)
            ->where('event', 'ticket_reopened')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('Ticket reopened to In Progress due to customer reply', $activity->properties['description']);

        // 2. Agent public reply to in_progress ticket transitions status to waiting_customer
        $this->actingAs($agent)
            ->from(route('tickets.show', $resolvedTicket))
            ->post(route('ticket_messages.store', $resolvedTicket), [
                'body' => 'Could you provide the exact steps to reproduce?',
                'is_internal' => false,
            ])
            ->assertRedirect(route('tickets.show', $resolvedTicket));

        $resolvedTicket->refresh();
        $this->assertSame(TicketStatus::WAITING_CUSTOMER, $resolvedTicket->status);

        $statusActivity = TicketActivity::query()
            ->where('ticket_id', $resolvedTicket->id)
            ->where('event', 'status_changed')
            ->latest('id')
            ->first();
        $this->assertNotNull($statusActivity);
        $this->assertSame('Ticket status changed to Waiting for Customer', $statusActivity->properties['description']);
    }

    #[Test]
    public function closed_ticket_reply_permissions_enforce_customer_block_and_staff_access(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Assigned Agent']);
        $otherAgent = User::factory()->agent()->create(['name' => 'Other Agent']);
        $admin = User::factory()->admin()->create(['name' => 'Admin']);
        $customer = User::factory()->customer()->create(['name' => 'Customer']);

        $closedTicket = Ticket::factory()->create([
            'status' => TicketStatus::CLOSED,
            'closed_at' => now()->subDays(2),
            'assigned_agent_id' => $agent->id,
            'created_by' => $customer->id,
        ]);

        // Customer attempt is forbidden (403)
        $this->actingAs($customer)
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Customer trying to message closed ticket',
            ])
            ->assertForbidden();

        // Other unassigned agent attempt is forbidden (403)
        $this->actingAs($otherAgent)
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Other agent trying to reply',
            ])
            ->assertForbidden();

        // Assigned Agent can reply (200/201 and message created)
        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Post-close explanation for the customer record.',
            ]);

        $response->assertRedirect(route('tickets.show', $closedTicket));
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $closedTicket->id,
            'user_id' => $agent->id,
            'body' => 'Post-close explanation for the customer record.',
        ]);

        // Ticket status remains CLOSED
        $closedTicket->refresh();
        $this->assertSame(TicketStatus::CLOSED, $closedTicket->status);

        // Admin can also reply
        $this->actingAs($admin)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.store', $closedTicket), [
                'body' => 'Admin administrative review note.',
            ])
            ->assertRedirect(route('tickets.show', $closedTicket));

        // Assigned Agent can add internal notes to closed tickets
        $this->actingAs($agent)
            ->from(route('tickets.show', $closedTicket))
            ->post(route('ticket_messages.internal_note', $closedTicket), [
                'body' => 'Internal post-mortem documentation note.',
            ])
            ->assertRedirect(route('tickets.show', $closedTicket));

        $note = TicketMessage::query()
            ->where('ticket_id', $closedTicket->id)
            ->where('body', 'Internal post-mortem documentation note.')
            ->first();

        $this->assertNotNull($note);
        $this->assertTrue($note->is_internal);
    }
}
