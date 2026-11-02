<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

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
                    $query->where('status', '!=', TicketStatus::CLOSED->value);
                },
            ])
            ->withMax([
                'assignedTickets as last_assigned_at' => function ($query): void {
                    $query->where('status', '!=', TicketStatus::CLOSED->value);
                },
            ], 'created_at')
            ->orderBy('active_tickets_count', 'asc')
            ->orderBy('last_assigned_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
    }
}
