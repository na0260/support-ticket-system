<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Models\Ticket;
use App\Models\User;

interface AssignmentStrategyInterface
{
    /**
     * Pick candidate agent for the given ticket.
     */
    public function pickAgent(Ticket $ticket): ?User;
}
