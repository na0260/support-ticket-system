<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\SlaStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingBreachNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Activity\TicketActivityLogger;
use Illuminate\Support\Facades\DB;

class SlaEscalationService
{
    public function __construct(
        private readonly TicketActivityLogger $activityLogger,
    ) {}

    /**
     * Escalate tickets that have passed 80% of their SLA window into APPROACHING_BREACH.
     * Idempotent: Only evaluates tickets still marked WITHIN_SLA.
     */
    public function escalateApproachingBreach(): int
    {
        $now = now();

        $tickets = Ticket::query()
            ->with(['assignedAgent', 'creator'])
            ->whereNotIn('status', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value])
            ->whereNull('resolved_at')
            ->where('sla_status', SlaStatus::WITHIN_SLA->value)
            ->whereNotNull('sla_warning_at')
            ->where('sla_warning_at', '<=', $now)
            ->where('sla_due_at', '>', $now)
            ->get();

        $count = 0;

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket, &$count): void {
                $affected = Ticket::query()
                    ->where('id', $ticket->id)
                    ->where('sla_status', SlaStatus::WITHIN_SLA->value)
                    ->update([
                        'sla_status' => SlaStatus::APPROACHING_BREACH->value,
                        'is_escalated' => true,
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    return;
                }

                $ticket->refresh();
                $count++;

                $this->activityLogger->log(
                    $ticket,
                    'sla_approaching_breach',
                    null,
                    [
                        'message' => 'Ticket automatically escalated due to SLA approaching breach',
                        'description' => 'Ticket automatically escalated due to SLA approaching breach',
                        'sla_status' => SlaStatus::APPROACHING_BREACH->value,
                        'sla_warning_at' => $ticket->sla_warning_at?->toIso8601String(),
                        'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
                    ],
                );

                // Notify assigned agent, or all admins if unassigned
                if ($ticket->assignedAgent !== null) {
                    $ticket->assignedAgent->notify(new SlaApproachingBreachNotification($ticket));
                } else {
                    $admins = User::query()->where('role', UserRole::ADMIN->value)->get();
                    foreach ($admins as $admin) {
                        $admin->notify(new SlaApproachingBreachNotification($ticket));
                    }
                }
            });
        }

        return $count;
    }

    /**
     * Escalate tickets that have exceeded their SLA resolution deadline into BREACHED.
     * Idempotent: Only evaluates tickets not yet marked BREACHED.
     */
    public function escalateBreached(): int
    {
        $now = now();

        $tickets = Ticket::query()
            ->with(['assignedAgent', 'creator'])
            ->whereNotIn('status', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value])
            ->whereNull('resolved_at')
            ->where('sla_status', '!=', SlaStatus::BREACHED->value)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $now)
            ->get();

        $count = 0;

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket, $now, &$count): void {
                $affected = Ticket::query()
                    ->where('id', $ticket->id)
                    ->where('sla_status', '!=', SlaStatus::BREACHED->value)
                    ->update([
                        'sla_status' => SlaStatus::BREACHED->value,
                        'is_escalated' => true,
                        'escalated_at' => $now,
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    return;
                }

                $ticket->refresh();
                $count++;

                $this->activityLogger->log(
                    $ticket,
                    'ticket_escalated',
                    null,
                    [
                        'message' => 'Ticket automatically escalated due to SLA breach',
                        'description' => 'Ticket automatically escalated due to SLA breach',
                        'reason' => 'SLA deadline exceeded',
                        'sla_status' => SlaStatus::BREACHED->value,
                        'escalated_at' => $now->toIso8601String(),
                    ],
                );

                // Notify assigned agent and all administrators
                $admins = User::query()->where('role', UserRole::ADMIN->value)->get();
                $recipients = collect($admins);

                if ($ticket->assignedAgent !== null && ! $admins->contains('id', $ticket->assignedAgent->id)) {
                    $recipients->push($ticket->assignedAgent);
                }

                foreach ($recipients as $recipient) {
                    $recipient->notify(new SlaBreachedNotification($ticket));
                }
            });
        }

        return $count;
    }
}
