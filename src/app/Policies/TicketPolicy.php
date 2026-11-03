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
     * - Administrators: can view any ticket across the system.
     * - Support Agents: can view unassigned tickets or tickets assigned to themselves.
     * - Customers: can only view tickets they created.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $ticket->assigned_agent_id === null || $ticket->assigned_agent_id === $user->id;
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

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $ticket->assigned_agent_id === null || $ticket->assigned_agent_id === $user->id;
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

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->id;
        }

        if ($user->isCustomer()) {
            return $ticket->created_by === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can manually trigger automatic ticket assignment.
     * Strictly restricted to Administrators (agents receive 403).
     */
    public function autoAssign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can assign/reassign the ticket.
     * - Administrator: can assign unassigned or reassign any assigned ticket.
     * - Assigned Agent: can reassign tickets assigned to themselves.
     * - Other Agents: forbidden (403).
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->id;
        }

        return false;
    }

    /**
     * Reassignment alias for assign.
     */
    public function reassign(User $user, Ticket $ticket): bool
    {
        return $this->assign($user, $ticket);
    }

    /**
     * Determine whether the user can claim the unassigned ticket.
     */
    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->isStaff()
            && $ticket->assigned_agent_id === null
            && ! $ticket->status->isTerminal();
    }

    /**
     * Determine whether the user can resolve the ticket.
     */
    public function resolve(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() && ! $ticket->status->isTerminal();
    }

    /**
     * Determine whether the user can close the ticket.
     * - Staff: true
     * - Customer: true if they own the ticket
     */
    public function close(User $user, Ticket $ticket): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    /**
     * Determine whether the user can delete the ticket.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }
}
