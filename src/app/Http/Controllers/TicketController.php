<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'tickets.index stub']);
    }

    public function create(): JsonResponse
    {
        return response()->json(['message' => 'tickets.create stub']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'tickets.store stub'], 201);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.show stub', 'ticket_id' => $ticket->id]);
    }

    public function edit(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.edit stub', 'ticket_id' => $ticket->id]);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.update stub', 'ticket_id' => $ticket->id]);
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.destroy stub', 'ticket_id' => $ticket->id]);
    }

    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.assign stub', 'ticket_id' => $ticket->id]);
    }

    public function claim(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.claim stub', 'ticket_id' => $ticket->id]);
    }

    public function resolve(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.resolve stub', 'ticket_id' => $ticket->id]);
    }

    public function close(Ticket $ticket): JsonResponse
    {
        return response()->json(['message' => 'tickets.close stub', 'ticket_id' => $ticket->id]);
    }
}
