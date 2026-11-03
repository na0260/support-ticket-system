<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view any tickets.
     * Query-level scoping (Ticket::visibleTo) filters records per role.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->role->isStaff()) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    /**
     * Determine whether the user can create tickets.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the ticket.
     * - Staff: can update status, priority, category, etc.
     * - Customer: can update subject/description only if the ticket is OPEN and not terminal.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        if ($user->role->isStaff()) {
            return true;
        }

        return $ticket->created_by === $user->id
            && $ticket->status === TicketStatus::OPEN;
    }

    /**
     * Determine whether the user can update the priority of the ticket.
     * Permitted strictly for:
     * - Customer (owner of the ticket)
     * - Administrator
     * - Assigned Agent (ticket.assigned_agent_id === user.id)
     *
     * All others (unassigned agents, other agents, non-owner customers) receive false (403).
     */
    public function updatePriority(User $user, Ticket $ticket): bool
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
     * Determine whether the user can assign/reassign the ticket.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->role->isStaff();
    }

    /**
     * Determine whether the user can claim the unassigned ticket.
     */
    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->role->isStaff()
            && $ticket->assigned_agent_id === null
            && ! $ticket->status->isTerminal();
    }

    /**
     * Determine whether the user can resolve the ticket.
     */
    public function resolve(User $user, Ticket $ticket): bool
    {
        return $user->role->isStaff() && ! $ticket->status->isTerminal();
    }

    /**
     * Determine whether the user can close the ticket.
     * - Staff: true
     * - Customer: true if they own the ticket
     */
    public function close(User $user, Ticket $ticket): bool
    {
        if ($user->role->isStaff()) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    /**
     * Determine whether the user can delete the ticket.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->role->isAdmin();
    }
}
