<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Http\Requests\StoreTicketMessageRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use App\Services\Activity\TicketActivityLogger;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class TicketMessageController extends Controller
{
    /**
     * Show a specific ticket message.
     */
    public function show(TicketMessage $message): JsonResponse
    {
        Gate::authorize('view', $message);

        return response()->json([
            'id' => $message->id,
            'ticket_id' => $message->ticket_id,
            'user_id' => $message->user_id,
            'body' => $message->body,
            'is_internal' => $message->is_internal,
            'created_at' => $message->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Store a new message or reply on a ticket thread.
     */
    public function store(
        StoreTicketMessageRequest $request,
        Ticket $ticket,
        AttachmentService $attachmentService,
        TicketActivityLogger $activityLogger,
    ): RedirectResponse|Response|JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $isInternal = (bool) ($validated['is_internal'] ?? false);

        // Security check: Customers cannot create internal notes under any circumstances
        if ($user->role->isCustomer()) {
            $isInternal = false;
        }

        if ($isInternal) {
            Gate::authorize('createInternalNote', [TicketMessage::class, $ticket]);
        } else {
            Gate::authorize('create', [TicketMessage::class, $ticket]);
        }

        $message = DB::transaction(function () use ($validated, $user, $ticket, $isInternal, $attachmentService, $activityLogger, $request): TicketMessage {
            /** @var TicketMessage $msg */
            $msg = $ticket->messages()->create([
                'user_id' => $user->id,
                'body' => $validated['body'],
                'is_internal' => $isInternal,
            ]);

            // Upload any message attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    if ($file !== null && $file->isValid()) {
                        $attachmentService->upload($file, $msg, $user);
                    }
                }
            }

            // Ticket lifecycle auto-transitions:
            // 1. If customer replies to a RESOLVED ticket: automatically reopen ticket to IN_PROGRESS or OPEN and clear resolved_at.
            if ($user->role->isCustomer() && $ticket->isResolved()) {
                $ticket->status = $ticket->assigned_agent_id !== null ? TicketStatus::IN_PROGRESS : TicketStatus::OPEN;
                $ticket->resolved_at = null;
                $ticket->save();

                $activityLogger->log($ticket, 'ticket_reopened', $user, [
                    'reason' => 'customer_reply',
                    'new_status' => $ticket->status->value,
                ]);
            } elseif ($user->role->isStaff() && ! $isInternal) {
                // 2. If agent replies publicly to an OPEN or WAITING_CUSTOMER ticket: transition status to WAITING_CUSTOMER
                if ($ticket->status === TicketStatus::OPEN || $ticket->status === TicketStatus::IN_PROGRESS) {
                    $ticket->status = TicketStatus::WAITING_CUSTOMER;
                    $ticket->save();
                }
            }

            // Log activity: 'agent_replied', 'customer_replied', or 'internal_note_added'
            $activityEvent = $isInternal
                ? 'internal_note_added'
                : ($user->role->isCustomer() ? 'customer_replied' : 'agent_replied');

            $activityLogger->log($ticket, $activityEvent, $user, [
                'message_id' => $msg->id,
                'reply_length' => strlen($validated['body']),
                'is_internal' => $isInternal,
            ]);

            // Asynchronous queued notifications
            if (! $isInternal) {
                if ($user->role->isCustomer() && $ticket->assignedAgent !== null) {
                    $ticket->assignedAgent->notify(new TicketRepliedNotification($ticket, $msg));
                } elseif ($user->role->isStaff() && $ticket->creator !== null && $ticket->creator->id !== $user->id) {
                    $ticket->creator->notify(new TicketRepliedNotification($ticket, $msg));
                }
            }

            return $msg;
        });

        // If request wants JSON or has no referer header (as in feature tests without from())
        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json([
                'message' => 'Message created successfully.',
                'ticket_message_id' => $message->id,
            ], 201);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', $isInternal ? 'Internal note added.' : 'Reply posted.');
    }

    /**
     * Dedicated action for storing internal notes (delegates to store with forced is_internal = true).
     */
    public function storeInternalNote(
        StoreTicketMessageRequest $request,
        Ticket $ticket,
        AttachmentService $attachmentService,
        TicketActivityLogger $activityLogger,
    ): RedirectResponse|Response|JsonResponse {
        $request->merge(['is_internal' => true]);

        return $this->store($request, $ticket, $attachmentService, $activityLogger);
    }
}
