<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DashboardAnalyticsService
{
    /**
     * Compute aggregated dashboard metrics for the authenticated user.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(User $user): array
    {
        $isStaff = $user->role->isStaff();

        if ($isStaff) {
            return $this->getStaffMetrics($user);
        }

        return $this->getCustomerMetrics($user);
    }

    /**
     * Compute staff KPI metrics and distributions.
     *
     * @return array<string, mixed>
     */
    private function getStaffMetrics(User $user): array
    {
        // Single conditional aggregation query for staff KPI counters, scoped by agent visibility
        $statsQuery = DB::table('tickets');
        if ($user->isAgent()) {
            $statsQuery->where(function ($q) use ($user): void {
                $q->whereNull('assigned_agent_id')
                    ->orWhere('assigned_agent_id', $user->id);
            });
        }

        $stats = $statsQuery
            ->selectRaw("
                COUNT(CASE WHEN status != 'closed' THEN 1 END) as total_active,
                COUNT(CASE WHEN assigned_agent_id IS NULL AND status != 'closed' THEN 1 END) as unassigned,
                COUNT(CASE WHEN priority = 'critical' AND status != 'closed' THEN 1 END) as critical,
                COUNT(CASE WHEN sla_status = 'breached' AND status != 'closed' THEN 1 END) as breached,
                COUNT(CASE WHEN sla_status = 'approaching_breach' AND status != 'closed' THEN 1 END) as approaching_breach,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as total_resolved,
                COUNT(CASE WHEN status = 'resolved' AND resolved_at >= ? THEN 1 END) as resolved_today
            ", [now()->startOfDay()])
            ->first();

        // Priority breakdown for active tickets
        $priorityCounts = Ticket::query()
            ->visibleTo($user)
            ->active()
            ->selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $priorityDistribution = [];
        foreach (TicketPriority::cases() as $priority) {
            $priorityDistribution[$priority->value] = (int) ($priorityCounts[$priority->value] ?? 0);
        }

        // Category breakdown for active tickets
        $categoryDistribution = Ticket::query()
            ->visibleTo($user)
            ->active()
            ->join('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id')
            ->selectRaw('ticket_categories.name, count(*) as count')
            ->groupBy('ticket_categories.name')
            ->pluck('count', 'name')
            ->toArray();

        // Active ticket distribution across agents (without N+1)
        $agentWorkload = User::query()
            ->agents()
            ->withCount(['assignedTickets' => fn ($q) => $q->active()])
            ->orderByDesc('assigned_tickets_count')
            ->get();

        // 5 most recent tickets
        $recentTickets = Ticket::query()
            ->visibleTo($user)
            ->with(['category', 'assignedAgent', 'creator'])
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'is_staff' => true,
            'kpis' => [
                'total_active' => (int) ($stats->total_active ?? 0),
                'unassigned' => (int) ($stats->unassigned ?? 0),
                'critical' => (int) ($stats->critical ?? 0),
                'breached' => (int) ($stats->breached ?? 0),
                'approaching_breach' => (int) ($stats->approaching_breach ?? 0),
                'total_resolved' => (int) ($stats->total_resolved ?? 0),
                'resolved_today' => (int) ($stats->resolved_today ?? 0),
            ],
            'priority_distribution' => $priorityDistribution,
            'category_distribution' => $categoryDistribution,
            'agent_workload' => $agentWorkload,
            'recent_tickets' => $recentTickets,
        ];
    }

    /**
     * Compute customer-isolated KPI metrics and recent activity.
     *
     * @return array<string, mixed>
     */
    private function getCustomerMetrics(User $user): array
    {
        $stats = DB::table('tickets')
            ->where('created_by', $user->id)
            ->selectRaw("
                COUNT(CASE WHEN status != 'closed' THEN 1 END) as total_active,
                COUNT(CASE WHEN status = 'open' THEN 1 END) as open_count,
                COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
                COUNT(CASE WHEN status = 'waiting_customer' THEN 1 END) as waiting_customer_count,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_count,
                COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_count,
                COUNT(*) as total_tickets
            ")
            ->first();

        // 5 most recent tickets created by customer
        $recentTickets = Ticket::query()
            ->where('created_by', $user->id)
            ->with(['category', 'assignedAgent'])
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'is_staff' => false,
            'kpis' => [
                'total_active' => (int) ($stats->total_active ?? 0),
                'open_count' => (int) ($stats->open_count ?? 0),
                'in_progress_count' => (int) ($stats->in_progress_count ?? 0),
                'waiting_customer_count' => (int) ($stats->waiting_customer_count ?? 0),
                'resolved_count' => (int) ($stats->resolved_count ?? 0),
                'closed_count' => (int) ($stats->closed_count ?? 0),
                'total_tickets' => (int) ($stats->total_tickets ?? 0),
            ],
            'recent_tickets' => $recentTickets,
        ];
    }
}
