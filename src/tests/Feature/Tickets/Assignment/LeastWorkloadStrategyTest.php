<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\LeastWorkloadStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LeastWorkloadStrategyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_selects_the_available_agent_with_the_lowest_active_ticket_count(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A', 'is_available' => true]);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B', 'is_available' => true]);
        $agentC = User::factory()->agent()->create(['name' => 'Agent C', 'is_available' => true]);

        // Agent A: 3 active tickets
        Ticket::factory()->count(3)->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Agent B: 1 active ticket
        Ticket::factory()->count(1)->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::OPEN,
        ]);

        // Agent C: 5 active tickets
        Ticket::factory()->count(5)->create([
            'assigned_agent_id' => $agentC->id,
            'status' => TicketStatus::WAITING_CUSTOMER,
        ]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        $this->assertNotNull($selected);
        $this->assertSame($agentB->id, $selected->id);
    }

    #[Test]
    public function it_skips_unavailable_agents_even_if_they_have_lower_workload(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A', 'is_available' => true]);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B', 'is_available' => false]);
        $agentC = User::factory()->agent()->create(['name' => 'Agent C', 'is_available' => true]);

        // Agent A has 3 active tickets
        Ticket::factory()->count(3)->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        // Agent B has 1 active ticket, but is unavailable
        Ticket::factory()->count(1)->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::OPEN,
        ]);

        // Agent C has 5 active tickets
        Ticket::factory()->count(5)->create([
            'assigned_agent_id' => $agentC->id,
            'status' => TicketStatus::OPEN,
        ]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        // Should skip Agent B and select Agent A
        $this->assertNotNull($selected);
        $this->assertSame($agentA->id, $selected->id);
    }

    #[Test]
    public function closed_tickets_do_not_count_towards_agent_workload(): void
    {
        $agentA = User::factory()->agent()->create(['is_available' => true]);
        $agentB = User::factory()->agent()->create(['is_available' => true]);

        // Agent A has 1 active ticket and 10 closed tickets
        Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);
        Ticket::factory()->count(10)->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::CLOSED,
        ]);

        // Agent B has 2 active tickets and 0 closed tickets
        Ticket::factory()->count(2)->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::OPEN,
        ]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        // Agent A only has 1 active ticket, so Agent A should be chosen over Agent B (2 active)
        $this->assertNotNull($selected);
        $this->assertSame($agentA->id, $selected->id);
    }

    #[Test]
    public function resolved_tickets_do_not_count_towards_agent_workload(): void
    {
        $agentA = User::factory()->agent()->create(['is_available' => true]);
        $agentB = User::factory()->agent()->create(['is_available' => true]);

        // Agent A has 1 active ticket and 5 resolved tickets
        Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);
        Ticket::factory()->count(5)->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::RESOLVED,
        ]);

        // Agent B has 2 active tickets
        Ticket::factory()->count(2)->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::OPEN,
        ]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        // Agent A only has 1 active ticket (resolved tickets excluded)
        $this->assertNotNull($selected);
        $this->assertSame($agentA->id, $selected->id);
    }

    #[Test]
    public function it_breaks_ties_between_agents_using_least_recently_assigned_agent(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A', 'is_available' => true]);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B', 'is_available' => true]);

        // Both agents have exactly 1 active ticket
        // Agent A was assigned recently (1 hour ago)
        Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
            'created_at' => now()->subHour(),
        ]);

        // Agent B was assigned longer ago (3 days ago)
        Ticket::factory()->create([
            'assigned_agent_id' => $agentB->id,
            'status' => TicketStatus::IN_PROGRESS,
            'created_at' => now()->subDays(3),
        ]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        // Tie-breaker picks Agent B because Agent B's last assignment was furthest in the past
        $this->assertNotNull($selected);
        $this->assertSame($agentB->id, $selected->id);
    }

    #[Test]
    public function agent_with_no_prior_assignments_is_preferred_on_tie(): void
    {
        $agentA = User::factory()->agent()->create(['name' => 'Agent A', 'is_available' => true]);
        $agentB = User::factory()->agent()->create(['name' => 'Agent B', 'is_available' => true]);

        // Agent A has a resolved ticket in the past (so last_assigned_at is not null)
        Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::RESOLVED,
            'created_at' => now()->subDay(),
        ]);

        // Both agents currently have 0 active tickets!
        // Agent B has never been assigned any ticket (last_assigned_at is NULL).
        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        $this->assertNotNull($selected);
        $this->assertSame($agentB->id, $selected->id);
    }

    #[Test]
    public function it_returns_null_when_no_agents_are_available(): void
    {
        // No agents or all agents marked is_available = false
        User::factory()->agent()->create(['is_available' => false]);
        User::factory()->customer()->create(['is_available' => true]);

        $newTicket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(LeastWorkloadStrategy::class);
        $selected = $strategy->pickAgent($newTicket);

        $this->assertNull($selected);
    }
}
