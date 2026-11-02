<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AgentWorkloadController extends Controller
{
    /**
     * Display the real-time agent workload operations board.
     */
    public function index(Request $request): View|JsonResponse
    {
        $agents = User::query()
            ->agents()
            ->withCount([
                'assignedTickets as active_tickets_count' => fn ($q) => $q->active(),
                'assignedTickets as critical_tickets_count' => fn ($q) => $q->active()->where('priority', TicketPriority::CRITICAL),
                'assignedTickets as at_risk_tickets_count' => fn ($q) => $q->active()->whereIn('sla_status', [SlaStatus::APPROACHING_BREACH, SlaStatus::BREACHED]),
                'assignedTickets as resolved_tickets_count' => fn ($q) => $q->where('status', TicketStatus::RESOLVED),
            ])
            ->orderByDesc('active_tickets_count')
            ->get();

        $totalAgents = $agents->count();
        $availableAgents = $agents->where('is_available', true)->count();
        $totalActiveTickets = $agents->sum('active_tickets_count');
        $averageWorkload = $totalAgents > 0 ? round($totalActiveTickets / $totalAgents, 1) : 0;

        $metrics = [
            'total_agents' => $totalAgents,
            'available_agents' => $availableAgents,
            'total_active_tickets' => $totalActiveTickets,
            'average_workload' => $averageWorkload,
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'metrics' => $metrics,
                'agents' => $agents,
            ]);
        }

        return view('admin.workload.index', [
            'metrics' => $metrics,
            'agents' => $agents,
        ]);
    }

    /**
     * Toggle an agent's triage availability.
     */
    public function toggleAvailability(Request $request, User $agent): RedirectResponse|JsonResponse
    {
        if (! $agent->role->isAgent()) {
            abort(404, 'User is not a support agent.');
        }

        $agent->is_available = ! $agent->is_available;
        $agent->save();

        $statusText = $agent->is_available ? 'marked as Available' : 'marked as Busy/Unavailable';
        $message = "Agent {$agent->name} is now {$statusText}.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'agent' => $agent,
            ]);
        }

        return redirect()->back()->with('status', $message);
    }
}
