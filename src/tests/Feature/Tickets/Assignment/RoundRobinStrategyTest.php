<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets\Assignment;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\RoundRobinStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoundRobinStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(RoundRobinStrategy::CACHE_KEY);
    }

    #[Test]
    public function it_distributes_tickets_in_circular_round_robin_sequence(): void
    {
        $agentA = User::factory()->agent()->create(['is_available' => true]);
        $agentB = User::factory()->agent()->create(['is_available' => true]);
        $agentC = User::factory()->agent()->create(['is_available' => true]);

        // Sorted by ID ascending
        $ordered = collect([$agentA, $agentB, $agentC])->sortBy('id')->values();
        $first = $ordered[0];
        $second = $ordered[1];
        $third = $ordered[2];

        $strategy = app(RoundRobinStrategy::class);

        $ticket1 = Ticket::factory()->create(['assigned_agent_id' => null]);
        $ticket2 = Ticket::factory()->create(['assigned_agent_id' => null]);
        $ticket3 = Ticket::factory()->create(['assigned_agent_id' => null]);
        $ticket4 = Ticket::factory()->create(['assigned_agent_id' => null]);

        $pick1 = $strategy->pickAgent($ticket1);
        $this->assertSame($first->id, $pick1?->id);

        $pick2 = $strategy->pickAgent($ticket2);
        $this->assertSame($second->id, $pick2?->id);

        $pick3 = $strategy->pickAgent($ticket3);
        $this->assertSame($third->id, $pick3?->id);

        // Circular wrap around back to first
        $pick4 = $strategy->pickAgent($ticket4);
        $this->assertSame($first->id, $pick4?->id);
    }

    #[Test]
    public function it_skips_unavailable_agents_during_round_robin(): void
    {
        $agentA = User::factory()->agent()->create(['is_available' => true]);
        $agentB = User::factory()->agent()->create(['is_available' => false]); // Unavailable
        $agentC = User::factory()->agent()->create(['is_available' => true]);

        $ordered = collect([$agentA, $agentC])->sortBy('id')->values();
        $first = $ordered[0];
        $second = $ordered[1];

        $strategy = app(RoundRobinStrategy::class);

        $ticket1 = Ticket::factory()->create(['assigned_agent_id' => null]);
        $ticket2 = Ticket::factory()->create(['assigned_agent_id' => null]);
        $ticket3 = Ticket::factory()->create(['assigned_agent_id' => null]);

        $pick1 = $strategy->pickAgent($ticket1);
        $this->assertSame($first->id, $pick1?->id);

        // Agent B is skipped, so next is second available agent
        $pick2 = $strategy->pickAgent($ticket2);
        $this->assertSame($second->id, $pick2?->id);

        // Wrap around
        $pick3 = $strategy->pickAgent($ticket3);
        $this->assertSame($first->id, $pick3?->id);
    }

    #[Test]
    public function it_returns_null_when_no_agents_are_available(): void
    {
        User::factory()->agent()->create(['is_available' => false]);
        $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

        $strategy = app(RoundRobinStrategy::class);
        $this->assertNull($strategy->pickAgent($ticket));
    }
}
