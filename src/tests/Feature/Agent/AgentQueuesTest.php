<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentQueuesTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $agent2;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->agent()->create(['name' => 'Agent One']);
        $this->agent2 = User::factory()->agent()->create(['name' => 'Agent Two']);
        $this->customer = User::factory()->customer()->create(['name' => 'Customer Charlie']);
    }

    #[Test]
    public function customer_is_forbidden_from_agent_queues(): void
    {
        $this->actingAs($this->customer)->get(route('agent.unassigned'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('agent.queue'))->assertForbidden();
    }

    #[Test]
    public function agent_can_view_unassigned_queue_and_claim_tickets(): void
    {
        $unassignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
            'subject' => 'Unassigned Critical Network Error',
            'created_by' => $this->customer->id,
        ]);

        $assignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => $this->agent2->id,
            'status' => TicketStatus::IN_PROGRESS,
            'subject' => 'Already Assigned Ticket',
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('agent.unassigned'));

        $response->assertOk();
        $response->assertSee('Unassigned Queue');
        $response->assertSee('Unassigned Critical Network Error');
        $response->assertDontSee('Already Assigned Ticket');

        // Claim the unassigned ticket
        $claimResponse = $this->actingAs($this->agent)
            ->from(route('agent.unassigned'))
            ->post(route('tickets.claim', $unassignedTicket));

        $claimResponse->assertRedirect(route('tickets.show', $unassignedTicket));

        $unassignedTicket->refresh();
        $this->assertSame($this->agent->id, $unassignedTicket->assigned_agent_id);
        $this->assertSame(TicketStatus::IN_PROGRESS, $unassignedTicket->status);
    }

    #[Test]
    public function agent_queue_only_shows_tickets_assigned_to_the_authenticated_agent(): void
    {
        $myTicket = Ticket::factory()->create([
            'assigned_agent_id' => $this->agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'subject' => 'My Personal Assigned Ticket Alpha',
            'created_by' => $this->customer->id,
        ]);

        $otherAgentTicket = Ticket::factory()->create([
            'assigned_agent_id' => $this->agent2->id,
            'status' => TicketStatus::IN_PROGRESS,
            'subject' => 'Agent Two Assigned Ticket Beta',
            'created_by' => $this->customer->id,
        ]);

        $unassignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
            'subject' => 'Unassigned Ticket Gamma',
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('agent.queue'));

        $response->assertOk();
        $response->assertSee('My Ticket Queue');
        $response->assertSee('My Personal Assigned Ticket Alpha');
        $response->assertDontSee('Agent Two Assigned Ticket Beta');
        $response->assertDontSee('Unassigned Ticket Gamma');

        // Verify JSON response metrics
        $jsonResponse = $this->actingAs($this->agent)->getJson(route('agent.queue'));
        $jsonResponse->assertOk()
            ->assertJsonPath('metrics.active_count', 1)
            ->assertJsonPath('tickets.data.0.id', $myTicket->id);
    }
}
