<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_the_full_ticket_graph_with_foreign_keys_and_casts(): void
    {
        $this->travelTo('2026-11-01 10:00:00');

        $category = TicketCategory::query()->create([
            'name' => 'Technical Support',
            'slug' => 'technical-support',
        ]);

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $priority = TicketPriority::HIGH;

        $ticket = Ticket::query()->create([
            'ticket_number' => $this->app->make(TicketNumberGenerator::class)->generate(),
            'subject' => 'Cannot log in',
            'description' => 'Login fails with a 500 error.',
            'priority' => $priority,
            'category_id' => $category->id,
            'assigned_agent_id' => $agent->id,
            'created_by' => $customer->id,
            'sla_due_at' => now()->addHours($priority->slaHours()),
            'sla_warning_at' => now()->addHours(6),
        ]);

        $publicReply = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Looking into it.',
        ]);

        $internalNote = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Suspect auth service outage.',
            'is_internal' => true,
        ]);

        $ticketAttachment = $ticket->attachments()->create([
            'file_path' => 'tickets/1/screenshot.png',
            'file_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'file_size' => 204_800,
            'uploaded_by' => $customer->id,
        ]);

        $messageAttachment = $publicReply->attachments()->create([
            'file_path' => 'tickets/1/messages/1/log.txt',
            'file_name' => 'log.txt',
            'mime_type' => 'text/plain',
            'file_size' => 1_024,
            'uploaded_by' => $agent->id,
        ]);

        $activity = TicketActivity::query()->create([
            'ticket_id' => $ticket->id,
            'causer_id' => $agent->id,
            'event' => 'ticket.assigned',
            'properties' => ['from' => null, 'to' => $agent->id],
        ]);

        $ticket = Ticket::query()
            ->with(['category', 'assignedAgent', 'creator', 'messages', 'attachments', 'activities'])
            ->findOrFail($ticket->id);

        // Ticket number + defaults
        $this->assertSame('TKT-2026-000001', $ticket->ticket_number);
        $this->assertSame(TicketStatus::OPEN, $ticket->status);
        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);

        // Enum + date casts
        $this->assertSame(TicketPriority::HIGH, $ticket->priority);
        $this->assertInstanceOf(Carbon::class, $ticket->sla_due_at);
        $this->assertTrue($ticket->sla_due_at->equalTo(Carbon::parse('2026-11-01 18:00:00')));
        $this->assertNull($ticket->escalated_at);

        // BelongsTo relationships
        $this->assertTrue($ticket->category->is($category));
        $this->assertTrue($ticket->creator->is($customer));
        $this->assertTrue($ticket->assignedAgent?->is($agent));

        // HasMany / MorphMany relationships
        $this->assertCount(2, $ticket->messages);
        $this->assertCount(1, $ticket->attachments);
        $this->assertTrue($ticket->attachments->first()?->is($ticketAttachment));
        $this->assertCount(1, $ticket->activities);

        // Message casts, relationships and scope
        $this->assertFalse($publicReply->fresh()?->is_internal);
        $this->assertTrue($internalNote->fresh()?->is_internal);
        $this->assertTrue($publicReply->ticket()->first()?->is($ticket));
        $this->assertTrue($publicReply->user()->first()?->is($agent));
        $this->assertSame(
            [$publicReply->id],
            $ticket->messages()->publicOnly()->pluck('id')->all(),
        );
        $this->assertTrue($publicReply->attachments()->first()?->is($messageAttachment));

        // Polymorphic attachment (morph map alias, not FQCN)
        $this->assertDatabaseHas('ticket_attachments', [
            'id' => $ticketAttachment->id,
            'attachable_type' => 'ticket',
            'disk' => 'private',
        ]);
        $this->assertDatabaseHas('ticket_attachments', [
            'id' => $messageAttachment->id,
            'attachable_type' => 'ticket_message',
        ]);
        $this->assertInstanceOf(Ticket::class, $ticketAttachment->fresh()?->attachable);
        $this->assertInstanceOf(TicketMessage::class, $messageAttachment->fresh()?->attachable);
        $this->assertTrue($ticketAttachment->uploader()->first()?->is($customer));
        $this->assertSame(204_800, $ticketAttachment->fresh()?->file_size);

        // Activity casts and relationships
        $activity = $activity->fresh();
        $this->assertSame(['from' => null, 'to' => $agent->id], $activity?->properties);
        $this->assertInstanceOf(Carbon::class, $activity?->created_at);
        $this->assertTrue($activity?->causer()->first()?->is($agent));
        $this->assertTrue($activity?->ticket()->first()?->is($ticket));

        // Inverse user relationships
        $this->assertSame([$ticket->id], $customer->createdTickets()->pluck('id')->all());
        $this->assertSame([$ticket->id], $agent->assignedTickets()->pluck('id')->all());
        $this->assertSame(2, $agent->ticketMessages()->count());
        $this->assertSame(1, $category->tickets()->count());
    }

    #[Test]
    public function user_role_is_cast_to_enum_and_defaults_to_customer(): void
    {
        $user = User::query()->create([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertSame(UserRole::CUSTOMER, $user->role);
        $this->assertSame(UserRole::CUSTOMER, $user->fresh()?->role);
        $this->assertTrue($user->fresh()?->is_available);
    }

    #[Test]
    public function role_cannot_be_mass_assigned(): void
    {
        $user = new User;
        $user->fill(['name' => 'Mallory']);

        $this->assertSame(['name', 'email', 'password'], $user->getFillable());
        $this->assertFalse($user->isFillable('role'));
        $this->assertFalse($user->isFillable('is_available'));
    }

    #[Test]
    public function agent_scopes_filter_by_role_and_availability(): void
    {
        $available = User::factory()->agent()->create();
        $unavailable = User::factory()->agent(available: false)->create();
        User::factory()->admin()->create();
        User::factory()->customer()->create();

        $this->assertEqualsCanonicalizing(
            [$available->id, $unavailable->id],
            User::query()->agents()->pluck('id')->all(),
        );
        $this->assertSame([$available->id], User::query()->availableAgents()->pluck('id')->all());
    }

    #[Test]
    public function ticket_scopes_isolate_and_filter_data_properly(): void
    {
        $customerA = User::factory()->customer()->create();
        $customerB = User::factory()->customer()->create();
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $ticketA = Ticket::factory()->for($customerA, 'creator')->create([
            'status' => TicketStatus::OPEN,
            'assigned_agent_id' => null,
        ]);
        $ticketB = Ticket::factory()->for($customerB, 'creator')->create([
            'status' => TicketStatus::IN_PROGRESS,
            'assigned_agent_id' => $agentA->id,
        ]);
        $ticketResolved = Ticket::factory()->for($customerA, 'creator')->create([
            'status' => TicketStatus::RESOLVED,
            'assigned_agent_id' => $agentA->id,
        ]);
        $ticketClosed = Ticket::factory()->for($customerB, 'creator')->create([
            'status' => TicketStatus::CLOSED,
            'assigned_agent_id' => $agentB->id,
        ]);

        // scopeVisibleTo
        $customerAVisible = Ticket::query()->visibleTo($customerA)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ticketA->id, $ticketResolved->id], $customerAVisible);

        $this->assertCount(4, Ticket::query()->visibleTo($agentA)->get());
        $this->assertCount(4, Ticket::query()->visibleTo($admin)->get());

        // scopeActive (excludes closed, includes open, in_progress, resolved)
        $activeIds = Ticket::query()->active()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ticketA->id, $ticketB->id, $ticketResolved->id], $activeIds);
        $this->assertNotContains($ticketClosed->id, $activeIds);

        // scopeResolvedOrClosed
        $resolvedOrClosedIds = Ticket::query()->resolvedOrClosed()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ticketResolved->id, $ticketClosed->id], $resolvedOrClosedIds);

        // scopeUnassigned (active + unassigned)
        $unassignedIds = Ticket::query()->unassigned()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ticketA->id], $unassignedIds);

        // scopeAssignedTo
        $agentAIds = Ticket::query()->assignedTo($agentA)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ticketB->id, $ticketResolved->id], $agentAIds);
    }

    #[Test]
    public function deleting_an_agent_unassigns_their_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $agent->delete();

        $this->assertNull($ticket->fresh()?->assigned_agent_id);
    }

    #[Test]
    public function deleting_a_user_nulls_out_uploaded_by_on_attachments(): void
    {
        $uploader = User::factory()->customer()->create();
        $ticket = Ticket::factory()->create();

        $attachment = $ticket->attachments()->create([
            'disk' => 'private',
            'file_path' => 'attachments/file.pdf',
            'file_name' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $uploader->id,
        ]);

        $uploader->delete();

        $this->assertNull($attachment->fresh()?->uploaded_by);
    }

    #[Test]
    public function force_deleting_a_ticket_cascades_to_messages_and_activities(): void
    {
        $ticket = Ticket::factory()->create();
        TicketMessage::factory()->for($ticket)->count(2)->create();
        TicketActivity::query()->create(['ticket_id' => $ticket->id, 'event' => 'ticket.created']);

        $ticket->delete();
        $this->assertSoftDeleted($ticket);
        $this->assertDatabaseCount('ticket_messages', 2);

        $ticket->forceDelete();
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertDatabaseCount('ticket_activities', 0);
    }

    #[Test]
    public function deleting_a_customer_cascades_to_their_tickets(): void
    {
        $ticket = Ticket::factory()->create();

        $ticket->creator()->firstOrFail()->delete();

        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    #[Test]
    public function enums_expose_domain_helpers(): void
    {
        $this->assertSame(48, TicketPriority::LOW->slaHours());
        $this->assertSame(24, TicketPriority::MEDIUM->slaHours());
        $this->assertSame(8, TicketPriority::HIGH->slaHours());
        $this->assertSame(2, TicketPriority::CRITICAL->slaHours());

        // Updated in Phase 2: only CLOSED is terminal; RESOLVED is still active
        $this->assertTrue(TicketStatus::WAITING_CUSTOMER->isActive());
        $this->assertTrue(TicketStatus::RESOLVED->isActive());
        $this->assertFalse(TicketStatus::RESOLVED->isTerminal());
        $this->assertTrue(TicketStatus::RESOLVED->isResolved());
        $this->assertTrue(TicketStatus::CLOSED->isTerminal());
        $this->assertFalse(TicketStatus::CLOSED->isActive());
        $this->assertFalse(TicketStatus::CLOSED->isResolved());
        $this->assertSame(
            [TicketStatus::OPEN, TicketStatus::IN_PROGRESS, TicketStatus::WAITING_CUSTOMER, TicketStatus::RESOLVED],
            TicketStatus::activeCases(),
        );

        $this->assertTrue(UserRole::ADMIN->isAdmin());
        $this->assertTrue(UserRole::AGENT->isAgent());
        $this->assertTrue(UserRole::CUSTOMER->isCustomer());
        $this->assertFalse(UserRole::CUSTOMER->isAgent());

        $this->assertSame('red', SlaStatus::BREACHED->color());
        $this->assertSame('Approaching Breach', SlaStatus::APPROACHING_BREACH->label());
    }
}
