<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TicketMessageController extends Controller
{
    public function show(TicketMessage $message): JsonResponse
    {
        return response()->json([
            'message' => 'ticket_messages.show stub',
            'ticket_message_id' => $message->id,
            'is_internal' => $message->is_internal,
        ]);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json([
            'message' => 'ticket_messages.store stub',
            'ticket_id' => $ticket->id,
        ], 201);
    }

    public function storeInternalNote(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json([
            'message' => 'ticket_messages.storeInternalNote stub',
            'ticket_id' => $ticket->id,
        ], 201);
    }
}
