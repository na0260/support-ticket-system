<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class TicketSearchService
{
    /**
     * Build the filtered query for tickets scoped to the authenticated user.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function query(User $user, array $filters = []): Builder
    {
        /** @var Builder<Ticket> $query */
        $query = Ticket::query()->visibleTo($user);

        // 1. Tab / Status Scope
        $statusFilter = $filters['status'] ?? null;
        if ($statusFilter !== null && $statusFilter !== '') {
            if (is_array($statusFilter)) {
                $query->whereIn('status', $statusFilter);
            } else {
                $query->where('status', $statusFilter);
            }
        } else {
            $tab = $filters['tab'] ?? 'active';
            if ($tab === 'resolved_or_closed') {
                $query->resolvedOrClosed();
            } else {
                $query->active();
            }
        }

        // 2. Ticket Number / ID filter
        $ticketNumber = $filters['ticket_number'] ?? $filters['ticket_id'] ?? null;
        if ($ticketNumber !== null && $ticketNumber !== '') {
            $ticketNumber = trim((string) $ticketNumber);
            $query->where('ticket_number', 'LIKE', "%{$ticketNumber}%");
        }

        // 3. Keyword / Text Search (subject, description, ticket_number)
        $keyword = $filters['keyword'] ?? $filters['search'] ?? null;
        if ($keyword !== null && $keyword !== '') {
            $sanitized = trim((string) $keyword);
            $query->where(function (Builder $sub) use ($sanitized): void {
                $sub->where('subject', 'LIKE', "%{$sanitized}%")
                    ->orWhere('description', 'LIKE', "%{$sanitized}%")
                    ->orWhere('ticket_number', 'LIKE', "%{$sanitized}%");
            });
        }

        // 4. Priority Filter
        $priority = $filters['priority'] ?? null;
        if ($priority !== null && $priority !== '') {
            if (is_array($priority)) {
                $query->whereIn('priority', $priority);
            } else {
                $query->where('priority', $priority);
            }
        }

        // 5. Category Filter
        $categoryId = $filters['category_id'] ?? null;
        if ($categoryId !== null && $categoryId !== '') {
            $query->where('category_id', (int) $categoryId);
        }

        // 6. Assigned Agent Filter
        $assignedAgentId = $filters['assigned_agent_id'] ?? null;
        if ($assignedAgentId !== null && $assignedAgentId !== '') {
            if ($assignedAgentId === 'unassigned') {
                $query->whereNull('assigned_agent_id');
            } else {
                $query->where('assigned_agent_id', (int) $assignedAgentId);
            }
        }

        // 7. Customer / Creator Filter (Staff only - ignored for customers to prevent ID tampering)
        if ($user->role->isStaff()) {
            $customerId = $filters['customer_id'] ?? $filters['created_by'] ?? null;
            if ($customerId !== null && $customerId !== '') {
                $query->where('created_by', (int) $customerId);
            }
        }

        // 8. SLA Status Filter
        $slaStatus = $filters['sla_status'] ?? null;
        if ($slaStatus !== null && $slaStatus !== '') {
            if (is_array($slaStatus)) {
                $query->whereIn('sla_status', $slaStatus);
            } else {
                $query->where('sla_status', $slaStatus);
            }
        }

        // 9. Date Range Filter on created_at
        $dateFrom = $filters['date_from'] ?? null;
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('created_at', '>=', Carbon::parse((string) $dateFrom)->startOfDay());
        }

        $dateTo = $filters['date_to'] ?? null;
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('created_at', '<=', Carbon::parse((string) $dateTo)->endOfDay());
        }

        // Eager load related models to eliminate N+1 queries
        return $query->with(['category', 'assignedAgent', 'creator'])->latest('id');
    }

    /**
     * Paginate the filtered ticket query preserving query string parameters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Ticket>
     */
    public function paginate(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($user, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }
}
