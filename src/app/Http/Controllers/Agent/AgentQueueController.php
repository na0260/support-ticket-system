<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\SlaStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AgentQueueController extends Controller
{
    /**
     * Display the authenticated agent's personal active ticket queue.
     */
    public function index(Request $request): View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tickets = Ticket::query()
            ->assignedTo($user)
            ->active()
            ->with(['category', 'creator'])
            ->orderBy('sla_due_at', 'asc')
            ->paginate(15);

        $metrics = [
            'active_count' => Ticket::query()->assignedTo($user)->active()->count(),
            'approaching_breach_count' => Ticket::query()->assignedTo($user)->active()->where('sla_status', SlaStatus::APPROACHING_BREACH)->count(),
            'breached_count' => Ticket::query()->assignedTo($user)->active()->where('sla_status', SlaStatus::BREACHED)->count(),
            'resolved_count' => Ticket::query()->assignedTo($user)->where('status', TicketStatus::RESOLVED)->count(),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'metrics' => $metrics,
                'tickets' => $tickets,
            ]);
        }

        return view('agent.queue', [
            'metrics' => $metrics,
            'tickets' => $tickets,
        ]);
    }
}
