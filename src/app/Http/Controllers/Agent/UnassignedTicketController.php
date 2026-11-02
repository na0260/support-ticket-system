<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UnassignedTicketController extends Controller
{
    /**
     * Display the triage queue of unassigned tickets sorted by earliest SLA due date.
     */
    public function index(Request $request): View|JsonResponse
    {
        $tickets = Ticket::query()
            ->unassigned()
            ->active()
            ->with(['category', 'creator'])
            ->orderBy('sla_due_at', 'asc')
            ->paginate(15);

        if ($request->wantsJson()) {
            return response()->json([
                'tickets' => $tickets,
            ]);
        }

        return view('agent.unassigned', [
            'tickets' => $tickets,
        ]);
    }
}
