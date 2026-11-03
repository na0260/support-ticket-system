<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/**
 * Strategy: Least Active Workload with Round-Robin Tie-Breaking.
 *
 * Operational Mechanics:
 * - Selects from active agents (role = 'agent' and is_available = true).
 * - Measures workload by counting non-terminal active tickets (status NOT IN ('resolved', 'closed')).
 * - Resolves ties using round-robin ordering: agents least recently assigned a ticket
 *   (or agents who have never been assigned a ticket) are prioritized first.
 * - Final deterministic tie-breaker: lowest agent user ID.
 *
 * Trade-offs:
 * - Advantages:
 *   1. Dynamic load balancing: Prevents overloading fast responders or underutilizing idle agents.
 *   2. Fair distribution: Accounts for active backlog rather than blind sequential rotation.
 *   3. Tie-breaking fairness: Incorporates round-robin recency so agents with identical counts share the load.
 * - Limitations:
 *   1. Ticket complexity agnostic: A single complex ticket counts the same as a quick trivial ticket.
 *   2. Dependent on timely ticket status transitions (resolving/closing tickets decrements workload).
 */
final class LeastWorkloadStrategy implements AssignmentStrategyInterface
{
    /**
     * Pick the available agent with the lowest active ticket workload.
     * Ties are broken by the agent whose last ticket assignment was furthest in the past, then smallest ID.
     */
    public function pickAgent(Ticket $ticket): ?User
    {
        return User::query()
            ->availableAgents()
            ->withCount([
                'assignedTickets as active_tickets_count' => function ($query): void {
                    $query->whereNotIn('status', [
                        TicketStatus::RESOLVED->value,
                        TicketStatus::CLOSED->value,
                    ]);
                },
            ])
            ->withMax('assignedTickets as last_assigned_at', 'created_at')
            ->orderBy('active_tickets_count', 'asc')
            ->orderByRaw('CASE WHEN last_assigned_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('last_assigned_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
    }
}
