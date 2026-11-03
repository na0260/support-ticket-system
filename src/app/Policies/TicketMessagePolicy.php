<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TicketStatus;
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
            return $user->isStaff();
        }

        return $user->can('view', $message->ticket);
    }

    /**
     * Determine whether the user can add a reply to the ticket.
     * - Closed ticket flow:
     *   - Customers are strictly forbidden from replying to permanently closed tickets (403).
     *   - Assigned Agent and Administrators can reply to closed tickets.
     * - Non-closed tickets:
     *   - Admin: permitted on any ticket.
     *   - Agent: forbidden on unassigned tickets; permitted strictly if assigned to this agent.
     *   - Customer: permitted strictly on tickets they created.
     */
    public function create(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::CLOSED) {
            // Customers cannot reply to permanently closed tickets
            if ($user->isCustomer()) {
                return false;
            }

            // Agents and Admins can reply to closed tickets
            return $user->isAdmin() || $ticket->assigned_agent_id === $user->id;
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
     * Determine whether the user can create an internal note on the ticket.
     * - Forbidden for Customers under all circumstances.
     * - Admin: permitted on any ticket.
     * - Agent: forbidden on unassigned tickets; permitted strictly if assigned to this agent.
     */
    public function createInternalNote(User $user, Ticket $ticket): bool
    {
        if ($user->isCustomer()) {
            return false;
        }

        if ($ticket->status === TicketStatus::CLOSED) {
            return $user->isAdmin() || $ticket->assigned_agent_id === $user->id;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->id;
        }

        return false;
    }
}
