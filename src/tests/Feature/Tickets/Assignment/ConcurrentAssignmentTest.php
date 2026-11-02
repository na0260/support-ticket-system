<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets\Assignment;

use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\TicketAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConcurrentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function first_claim_on_unassigned_ticket_succeeds(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.claim', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('status');

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
    public function claiming_ticket_during_race_returns_409_conflict(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $this->mock(TicketAssignmentService::class, function ($mock): void {
            $mock->shouldReceive('claim')
                ->once()
                ->andThrow(new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                ));
        });

        $response = $this->actingAs($agent)
            ->postJson(route('tickets.claim', $ticket));

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'This ticket has already been claimed or assigned to another agent.',
        ]);
    }

    #[Test]
    public function claiming_ticket_during_race_redirects_with_error_flash_for_web(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $this->mock(TicketAssignmentService::class, function ($mock): void {
            $mock->shouldReceive('claim')
                ->once()
                ->andThrow(new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                ));
        });

        $response = $this->actingAs($agent)
            ->from(route('tickets.show', $ticket))
            ->post(route('tickets.claim', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('error', 'This ticket was just claimed or assigned to another agent.');
    }

    #[Test]
    public function service_claim_throws_domain_exception_when_ticket_already_assigned(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => $agentA->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $service = app(TicketAssignmentService::class);

        $this->expectException(TicketAlreadyAssignedException::class);
        $this->expectExceptionMessage('This ticket has already been claimed or assigned to another agent.');

        $service->claim($ticket, $agentB);
    }

    #[Test]
    public function concurrent_claims_result_in_exactly_one_winner_and_one_loser(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $staleTicketForB = clone $ticket;

        $service = app(TicketAssignmentService::class);

        // First agent claims successfully
        $claimedTicket = $service->claim($ticket, $agentA);
        $this->assertSame($agentA->id, $claimedTicket->assigned_agent_id);

        // Second agent attempting to claim the stale unassigned ticket instance fails atomically
        $secondClaimFailed = false;
        try {
            $service->claim($staleTicketForB, $agentB);
        } catch (TicketAlreadyAssignedException) {
            $secondClaimFailed = true;
        }

        $this->assertTrue($secondClaimFailed);

        $ticket->refresh();
        $this->assertSame($agentA->id, $ticket->assigned_agent_id);
        $this->assertDatabaseCount('ticket_activities', 1);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'causer_id' => $agentA->id,
            'event' => 'ticket_claimed',
        ]);
    }

    #[Test]
    public function concurrent_manual_assignments_on_unassigned_ticket_protects_against_race(): void
    {
        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();
        $agent1 = User::factory()->agent()->create();
        $agent2 = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $staleTicketForB = clone $ticket;

        $service = app(TicketAssignmentService::class);

        // Admin A assigns first
        $service->assign($ticket, $agent1, $adminA);

        // Admin B attempts to assign based on original stale unassigned ticket
        $this->expectException(TicketAlreadyAssignedException::class);
        $service->assign($staleTicketForB, $agent2, $adminB);
    }
}
