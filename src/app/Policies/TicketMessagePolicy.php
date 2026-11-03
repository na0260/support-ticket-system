<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;

class TicketMessagePolicy
{
    /**
     * Determine whether the user can view the ticket message.
     * Internal notes: strictly Staff (Admin & Agent). Forbidden for Customers even if ticket owner.
     * Public replies: allowed if the user can view the parent ticket.
     */
    public function view(User $user, TicketMessage $message): bool
    {
        if ($message->is_internal) {
            return $user->role->isStaff();
        }

        return $user->can('view', $message->ticket);
    }

    /**
     * Determine whether the user can add a reply to the ticket.
     * - No replies are permitted once a ticket is terminal (CLOSED).
     * - Admin: permitted on any non-terminal ticket.
     * - Agent: forbidden on unassigned tickets; permitted strictly if assigned to this agent.
     * - Customer: permitted strictly on tickets they created.
     */
    public function create(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role->isAgent()) {
            return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->id;
        }

        if ($user->role->isCustomer()) {
            return $ticket->created_by === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create an internal note on the ticket.
     * - Strictly reserved for Staff (Admin & Assigned Agent).
     * - Forbidden for Customers under all circumstances.
     * - Forbidden on unassigned tickets for agents (must be assigned first).
     * - Forbidden for agents not assigned to the ticket.
     */
    public function createInternalNote(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role->isAgent()) {
            return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->id;
        }

        return false;
    }
}
