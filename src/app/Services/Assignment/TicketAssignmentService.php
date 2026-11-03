<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Services\Activity\TicketActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service: Ticket Assignment and Concurrency Control Engine.
 *
 * Architecture & Strategy Pattern:
 * - Decouples assignment algorithms via the Strategy Pattern (AssignmentStrategyInterface).
 * - Supported strategies:
 *   1. 'workload' (LeastWorkloadStrategy):
 *      - Assigns to available agents with the fewest active (non-resolved, non-closed) tickets.
 *      - Breaks ties using round-robin recency (least recently assigned agent).
 *      - Best for customer service teams where ticket resolution velocity varies widely.
 *   2. 'round_robin' (RoundRobinStrategy):
 *      - Sequential circular distribution across available agents.
 *      - Best for homogeneous ticket streams with consistent resolution times.
 *      - Trade-off: Blind to individual backlog depth and ticket complexity.
 *
 * Concurrency Protection:
 * - Enforces pessimistic database locking (Ticket::where('id', $id)->lockForUpdate()->first())
 *   inside database transactions to protect against race conditions when concurrent agents claim
 *   or assign the same ticket simultaneously.
 */
class TicketAssignmentService
{
    public function __construct(
        private readonly LeastWorkloadStrategy $workloadStrategy,
        private readonly RoundRobinStrategy $roundRobinStrategy,
        private readonly TicketActivityLogger $activityLogger,
    ) {}

    /**
     * Resolve assignment strategy instance.
     */
    public function resolveStrategy(?string $strategyName = null): AssignmentStrategyInterface
    {
        $strategy = $strategyName ?? (string) config('tickets.assignment.default_strategy', 'workload');

        return match ($strategy) {
            'round_robin' => $this->roundRobinStrategy,
            default => $this->workloadStrategy,
        };
    }

    /**
     * Manually assign or reassign a ticket to a specific agent (or unassign if $agent is null).
     * Protects initial unassigned claims against concurrent assignments using pessimistic row-locking.
     *
     * @throws TicketAlreadyAssignedException
     */
    public function assign(Ticket $ticket, ?User $agent, ?User $causer = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $agent, $causer): Ticket {
            $previousAgentId = $ticket->assigned_agent_id;

            // If unassigning
            if ($agent === null) {
                $ticket->assigned_agent_id = null;
                $ticket->save();

                $this->activityLogger->log($ticket, 'ticket_assigned', $causer, [
                    'from' => $previousAgentId,
                    'to' => null,
                ]);

                return $ticket;
            }

            // If the ticket was previously unassigned, enforce pessimistic locking race protection
            if ($previousAgentId === null) {
                /** @var Ticket|null $lockedTicket */
                $lockedTicket = Ticket::query()
                    ->where('id', $ticket->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedTicket === null || $lockedTicket->assigned_agent_id !== null || $lockedTicket->status->isTerminal()) {
                    throw new TicketAlreadyAssignedException(
                        'This ticket has already been claimed or assigned to another agent.'
                    );
                }

                $lockedTicket->assigned_agent_id = $agent->id;
                $lockedTicket->status = TicketStatus::IN_PROGRESS;
                $lockedTicket->save();

                $ticket = $lockedTicket;
            } else {
                // Reassignment by authorized staff
                $ticket->assigned_agent_id = $agent->id;
                if ($ticket->status === TicketStatus::OPEN) {
                    $ticket->status = TicketStatus::IN_PROGRESS;
                }
                $ticket->save();
            }

            $this->activityLogger->log($ticket, 'ticket_assigned', $causer, [
                'from' => $previousAgentId,
                'to' => $agent->id,
                'assigned_agent_id' => $agent->id,
            ]);

            // Notify assigned agent
            if ($causer === null || $agent->id !== $causer->id) {
                $agent->notify(new TicketAssignedNotification($ticket));
            }

            return $ticket;
        });
    }

    /**
     * Agent claims an unassigned ticket with strict pessimistic row locking concurrency protection.
     *
     * @throws TicketAlreadyAssignedException
     */
    public function claim(Ticket $ticket, User $agent): Ticket
    {
        return DB::transaction(function () use ($ticket, $agent): Ticket {
            /** @var Ticket|null $lockedTicket */
            $lockedTicket = Ticket::query()
                ->where('id', $ticket->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTicket === null || $lockedTicket->assigned_agent_id !== null || $lockedTicket->status->isTerminal()) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                );
            }

            $lockedTicket->assigned_agent_id = $agent->id;
            $lockedTicket->status = TicketStatus::IN_PROGRESS;
            $lockedTicket->save();

            $this->activityLogger->log($lockedTicket, 'ticket_claimed', $agent, [
                'claimed_by' => $agent->id,
                'assigned_agent_id' => $agent->id,
            ]);

            $agent->notify(new TicketAssignedNotification($lockedTicket));

            return $lockedTicket;
        });
    }

    /**
     * Automatically assign a ticket using the configured or requested strategy.
     * If no agents are available, leaves ticket unassigned and triggers an alert.
     *
     * @throws TicketAlreadyAssignedException
     */
    public function autoAssign(Ticket $ticket, ?string $strategyName = null, ?User $causer = null): ?User
    {
        if ($ticket->status === TicketStatus::CLOSED) {
            return null;
        }

        $strategyKey = $strategyName ?? (string) config('tickets.assignment.default_strategy', 'workload');
        $strategy = $this->resolveStrategy($strategyKey);

        $candidate = $strategy->pickAgent($ticket);

        if ($candidate === null) {
            Log::warning(
                sprintf('Auto-assignment alert: No available agents to assign ticket #%s (ID: %d). Ticket remains unassigned.', $ticket->ticket_number, $ticket->id),
                [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'strategy' => $strategyKey,
                ]
            );

            $this->activityLogger->log($ticket, 'ticket_auto_assignment_failed', $causer, [
                'reason' => 'no_available_agents',
                'strategy' => $strategyKey,
            ]);

            return null;
        }

        return DB::transaction(function () use ($ticket, $candidate, $strategyKey, $causer): User {
            /** @var Ticket|null $lockedTicket */
            $lockedTicket = Ticket::query()
                ->where('id', $ticket->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTicket === null || $lockedTicket->assigned_agent_id !== null || $lockedTicket->status->isTerminal()) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                );
            }

            $lockedTicket->assigned_agent_id = $candidate->id;
            $lockedTicket->status = TicketStatus::IN_PROGRESS;
            $lockedTicket->save();

            $this->activityLogger->log($lockedTicket, 'ticket_auto_assigned', $causer, [
                'strategy' => $strategyKey,
                'assigned_agent_id' => $candidate->id,
            ]);

            $candidate->notify(new TicketAssignedNotification($lockedTicket));

            return $candidate;
        });
    }
}
