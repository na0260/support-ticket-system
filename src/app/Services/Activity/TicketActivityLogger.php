<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;

class TicketActivityLogger
{
    /**
     * Record an audit activity entry for a ticket.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        Ticket $ticket,
        string $event,
        ?User $causer = null,
        array $properties = [],
    ): TicketActivity {
        /** @var TicketActivity $activity */
        $activity = TicketActivity::query()->create([
            'ticket_id' => $ticket->id,
            'causer_id' => $causer?->id,
            'event' => $event,
            'properties' => $properties,
        ]);

        return $activity;
    }
}
