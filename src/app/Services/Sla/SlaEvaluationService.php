<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\SlaStatus;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Computes and evaluates a ticket's SLA deadlines.
 *
 * Status rules:
 *  - Active tickets: BREACHED once now >= sla_due_at; APPROACHING_BREACH once
 *    now >= sla_warning_at; otherwise WITHIN_SLA.
 *  - Resolved/closed tickets are frozen on their completion instant
 *    (resolved_at, falling back to closed_at): BREACHED only if completed
 *    strictly after sla_due_at. Without any completion timestamp the last
 *    recorded sla_status is kept.
 */
final readonly class SlaEvaluationService
{
    public function __construct(
        private BusinessHoursCalculator $calculator,
    ) {}

    public function evaluate(Ticket $ticket, ?CarbonInterface $now = null): SlaStatus
    {
        $dueAt = $ticket->sla_due_at;

        if ($dueAt === null) {
            throw new LogicException('Cannot evaluate SLA: the ticket has no sla_due_at. Call applySla() first.');
        }

        if ($ticket->isResolved() || $ticket->status->isTerminal()) {
            return $this->frozenStatus($ticket, $dueAt);
        }

        $now = CarbonImmutable::instance($now ?? Date::now());

        if ($now->greaterThanOrEqualTo($dueAt)) {
            return SlaStatus::BREACHED;
        }

        $warningAt = $ticket->sla_warning_at;

        if ($warningAt !== null && $now->greaterThanOrEqualTo($warningAt)) {
            return SlaStatus::APPROACHING_BREACH;
        }

        return SlaStatus::WITHIN_SLA;
    }

    /**
     * Computes and assigns sla_due_at, sla_warning_at and sla_status (not persisted).
     *
     * The SLA clock starts at $startDate, falling back to the ticket's created_at, then now.
     */
    public function applySla(Ticket $ticket, ?CarbonInterface $startDate = null): Ticket
    {
        $start = CarbonImmutable::instance($startDate ?? $ticket->created_at ?? Date::now());

        $policy = SlaPolicy::forPriority($ticket->priority);
        $threshold = $policy->warning_threshold_percentage;

        $dueAt = $this->calculator->calculateDueAt($start, $ticket->priority);
        $warningAt = $this->calculator->calculateWarningAt($start, $dueAt, $threshold);

        // Eloquent stores the wall-clock time of the Carbon instance as-is, so
        // convert to the application timezone to persist the correct instant.
        $storageTimezone = (string) config('app.timezone', 'UTC');

        $ticket->sla_due_at = $dueAt->setTimezone($storageTimezone);
        $ticket->sla_warning_at = $warningAt->setTimezone($storageTimezone);
        $ticket->sla_status = $this->evaluate($ticket);

        return $ticket;
    }

    private function frozenStatus(Ticket $ticket, CarbonInterface $dueAt): SlaStatus
    {
        $completedAt = $ticket->resolved_at ?? $ticket->closed_at;

        if ($completedAt === null) {
            return $ticket->sla_status ?? SlaStatus::WITHIN_SLA;
        }

        return $completedAt->isAfter($dueAt) ? SlaStatus::BREACHED : SlaStatus::WITHIN_SLA;
    }
}
