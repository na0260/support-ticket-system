<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_can_create_ticket_with_sla_and_activity_logging(): void
    {
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::HIGH->value,
                'subject' => 'Payment gateway timeout on checkout',
                'description' => 'Customers are reporting HTTP 504 gateway timeouts when processing payments.',
            ]);

        $ticket = Ticket::query()->where('subject', 'Payment gateway timeout on checkout')->first();
        $this->assertNotNull($ticket);

        $response->assertRedirect(route('tickets.show', $ticket));

        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{6}$/', $ticket->ticket_number);
        $this->assertSame(TicketStatus::OPEN, $ticket->status);
        $this->assertSame(TicketPriority::HIGH, $ticket->priority);
        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);
        $this->assertNotNull($ticket->sla_due_at);
        $this->assertNotNull($ticket->sla_warning_at);
        $this->assertTrue($ticket->sla_due_at->isAfter(now()));
        $this->assertTrue($ticket->sla_warning_at->isBefore($ticket->sla_due_at));

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $customer->id,
            'event' => 'ticket_created',
        ]);
    }

    #[Test]
    public function ticket_creation_fails_validation_for_invalid_inputs(): void
    {
        $customer = User::factory()->customer()->create();
        $inactiveCategory = TicketCategory::factory()->inactive()->create();

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $inactiveCategory->id,
                'priority' => 'invalid_priority',
                'subject' => '',
                'description' => 'short',
            ]);

        $response->assertRedirect(route('tickets.create'));
        $response->assertSessionHasErrors(['category_id', 'priority', 'subject', 'description']);
        $this->assertDatabaseCount('tickets', 0);
    }

    #[Test]
    public function agent_or_admin_can_assign_ticket_to_agent(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.assign', $ticket), [
                'assigned_agent_id' => $agent->id,
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $admin->id,
            'event' => 'ticket_assigned',
        ]);
    }

    #[Test]
    public function agent_can_claim_unassigned_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.claim', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $agent->id,
            'event' => 'ticket_claimed',
        ]);
    }

    #[Test]
    public function authorized_user_can_resolve_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'resolved_at' => null,
        ]);

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.resolve', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::RESOLVED, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $agent->id,
            'event' => 'ticket_resolved',
        ]);
    }

    #[Test]
    public function authorized_user_can_close_ticket(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'status' => TicketStatus::RESOLVED,
            'closed_at' => null,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.close', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::CLOSED, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $admin->id,
            'event' => 'ticket_closed',
        ]);
    }

    #[Test]
    public function customer_reply_to_resolved_ticket_reopens_it(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::RESOLVED,
            'resolved_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($customer)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'The bug is still happening after the patch was applied.',
                'is_internal' => false,
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket->status);
        $this->assertNull($ticket->resolved_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $customer->id,
            'event' => 'ticket_reopened',
        ]);
    }

    #[Test]
    public function closed_ticket_rejects_new_replies(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'status' => TicketStatus::CLOSED,
            'closed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($customer)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Trying to reply to a closed ticket',
                'is_internal' => false,
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function ticket_index_filters_active_and_resolved_closed(): void
    {
        $customer = User::factory()->customer()->create();

        $activeTicket = Ticket::factory()->for($customer, 'creator')->create([
            'status' => TicketStatus::OPEN,
            'subject' => 'Active Ticket Alpha',
        ]);

        $closedTicket = Ticket::factory()->for($customer, 'creator')->create([
            'status' => TicketStatus::CLOSED,
            'subject' => 'Closed Ticket Beta',
        ]);

        // Active tab
        $responseActive = $this->actingAs($customer)->get(route('tickets.index', ['tab' => 'active']));
        $responseActive->assertOk();
        $responseActive->assertSee('Active Ticket Alpha');
        $responseActive->assertDontSee('Closed Ticket Beta');

        // Resolved/Closed tab
        $responseClosed = $this->actingAs($customer)->get(route('tickets.index', ['tab' => 'resolved_or_closed']));
        $responseClosed->assertOk();
        $responseClosed->assertSee('Closed Ticket Beta');
        $responseClosed->assertDontSee('Active Ticket Alpha');
    }
}
