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
     * No replies are permitted once a ticket is terminal (CLOSED).
     * Customers may only reply to their own tickets.
     */
    public function create(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        if ($user->role->isStaff()) {
            return true;
        }

        return $ticket->created_by === $user->id;
    }

    /**
     * Determine whether the user can create an internal note on the ticket.
     * Strictly reserved for Admin and Agent. Forbidden for Customers under all circumstances.
     */
    public function createInternalNote(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isTerminal()) {
            return false;
        }

        return $user->role->isStaff();
    }
}
