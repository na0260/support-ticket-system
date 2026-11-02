<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class RoundRobinStrategy implements AssignmentStrategyInterface
{
    public const string CACHE_KEY = 'ticket_assignment_last_agent_id';

    /**
     * Pick the next available agent using circular round-robin rotation.
     */
    public function pickAgent(Ticket $ticket): ?User
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $availableAgents */
        $availableAgents = User::query()
            ->availableAgents()
            ->orderBy('id', 'asc')
            ->get();

        if ($availableAgents->isEmpty()) {
            return null;
        }

        $lastAgentId = Cache::get(self::CACHE_KEY);

        /** @var User|null $nextAgent */
        $nextAgent = null;

        if ($lastAgentId !== null) {
            $nextAgent = $availableAgents->first(static fn (User $agent): bool => $agent->id > (int) $lastAgentId);
        }

        // Wrap around to the first available agent in the sequence
        if ($nextAgent === null) {
            $nextAgent = $availableAgents->first();
        }

        Cache::forever(self::CACHE_KEY, $nextAgent->id);

        return $nextAgent;
    }
}
