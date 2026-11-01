<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;

class TicketAttachmentPolicy
{
    /**
     * Determine whether the user can download the attachment.
     *
     * Resolves the root attachable:
     *  - If attached to Ticket: user must be allowed to view the Ticket.
     *  - If attached to TicketMessage: user must be allowed to view the TicketMessage
     *    (which automatically denies internal-note attachments to Customers).
     */
    public function download(User $user, TicketAttachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        if ($attachable instanceof TicketMessage) {
            return $user->can('view', $attachable);
        }

        if ($attachable instanceof Ticket) {
            return $user->can('view', $attachable);
        }

        return false;
    }
}
