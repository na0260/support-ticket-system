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
     * Protects initial unassigned claims against concurrent assignments.
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

            // If the ticket was previously unassigned, enforce atomic race-protected assignment
            if ($previousAgentId === null) {
                $affected = Ticket::query()
                    ->where('id', $ticket->id)
                    ->whereNull('assigned_agent_id')
                    ->whereNotIn('status', [TicketStatus::CLOSED->value])
                    ->update([
                        'assigned_agent_id' => $agent->id,
                        'status' => TicketStatus::IN_PROGRESS->value,
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    throw new TicketAlreadyAssignedException(
                        'This ticket has already been claimed or assigned to another agent.'
                    );
                }

                $ticket->refresh();
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
     * Agent claims an unassigned ticket with strict atomic concurrency protection.
     *
     * @throws TicketAlreadyAssignedException
     */
    public function claim(Ticket $ticket, User $agent): Ticket
    {
        return DB::transaction(function () use ($ticket, $agent): Ticket {
            if ($ticket->assigned_agent_id !== null) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                );
            }

            $affected = Ticket::query()
                ->where('id', $ticket->id)
                ->whereNull('assigned_agent_id')
                ->whereNotIn('status', [TicketStatus::CLOSED->value])
                ->update([
                    'assigned_agent_id' => $agent->id,
                    'status' => TicketStatus::IN_PROGRESS->value,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                );
            }

            $ticket->refresh();

            $this->activityLogger->log($ticket, 'ticket_claimed', $agent, [
                'claimed_by' => $agent->id,
                'assigned_agent_id' => $agent->id,
            ]);

            $agent->notify(new TicketAssignedNotification($ticket));

            return $ticket;
        });
    }

    /**
     * Automatically assign a ticket using the configured or requested strategy.
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
            $this->activityLogger->log($ticket, 'ticket_auto_assignment_failed', $causer, [
                'reason' => 'no_available_agents',
                'strategy' => $strategyKey,
            ]);

            return null;
        }

        return DB::transaction(function () use ($ticket, $candidate, $strategyKey, $causer): User {
            $affected = Ticket::query()
                ->where('id', $ticket->id)
                ->whereNull('assigned_agent_id')
                ->whereNotIn('status', [TicketStatus::CLOSED->value])
                ->update([
                    'assigned_agent_id' => $candidate->id,
                    'status' => TicketStatus::IN_PROGRESS->value,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed or assigned to another agent.'
                );
            }

            $ticket->refresh();

            $this->activityLogger->log($ticket, 'ticket_auto_assigned', $causer, [
                'strategy' => $strategyKey,
                'assigned_agent_id' => $candidate->id,
            ]);

            $candidate->notify(new TicketAssignedNotification($ticket));

            return $candidate;
        });
    }
}
