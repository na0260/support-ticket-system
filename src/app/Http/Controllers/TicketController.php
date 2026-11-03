<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketPriorityRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketResolvedNotification;
use App\Services\Activity\TicketActivityLogger;
use App\Services\Assignment\TicketAssignmentService;
use App\Services\AttachmentService;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\Sla\SlaEvaluationService;
use App\Services\TicketNumberGenerator;
use App\Services\TicketSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class TicketController extends Controller
{
    /**
     * List tickets visible to the authenticated user with tabs for Active vs Resolved/Closed and multi-filtering.
     */
    public function index(Request $request, TicketSearchService $searchService): View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->query();
        $tab = (string) ($filters['tab'] ?? 'active');

        $tickets = $searchService->paginate($user, $filters, 15);

        $activeCount = Ticket::query()->visibleTo($user)->active()->count();
        $resolvedOrClosedCount = Ticket::query()->visibleTo($user)->resolvedOrClosed()->count();

        if ($request->wantsJson()) {
            return response()->json([
                'tickets' => $tickets,
                'current_tab' => $tab,
                'active_count' => $activeCount,
                'resolved_or_closed_count' => $resolvedOrClosedCount,
                'filters' => $filters,
            ]);
        }

        $categories = TicketCategory::query()->where('is_active', true)->orderBy('name')->get();
        $agents = $user->role->isStaff() ? User::agents()->orderBy('name')->get() : collect();
        $priorities = TicketPriority::cases();
        $statuses = TicketStatus::cases();
        $slaStatuses = SlaStatus::cases();

        return view('tickets.index', [
            'tickets' => $tickets,
            'currentTab' => $tab,
            'activeCount' => $activeCount,
            'resolvedOrClosedCount' => $resolvedOrClosedCount,
            'filters' => $filters,
            'categories' => $categories,
            'agents' => $agents,
            'priorities' => $priorities,
            'statuses' => $statuses,
            'slaStatuses' => $slaStatuses,
        ]);
    }

    /**
     * Show ticket creation form.
     */
    public function create(Request $request): View|JsonResponse
    {
        $categories = TicketCategory::query()->where('is_active', true)->orderBy('name')->get();
        $priorities = TicketPriority::cases();

        if ($request->wantsJson()) {
            return response()->json([
                'categories' => $categories,
                'priorities' => $priorities,
            ]);
        }

        return view('tickets.create', [
            'categories' => $categories,
            'priorities' => $priorities,
        ]);
    }

    /**
     * Store newly created ticket inside a database transaction.
     */
    public function store(
        StoreTicketRequest $request,
        TicketNumberGenerator $numberGen,
        BusinessHoursCalculator $slaCalc,
        AttachmentService $attachmentService,
        TicketAssignmentService $assignmentService,
        TicketActivityLogger $activityLogger,
    ): RedirectResponse|JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $priority = TicketPriority::from($validated['priority']);
        $now = now();

        $ticket = DB::transaction(function () use ($validated, $user, $priority, $now, $numberGen, $slaCalc, $attachmentService, $activityLogger, $request): Ticket {
            $ticketNumber = $numberGen->generate();
            $dueAt = $slaCalc->calculateDueAt($now, $priority);
            $policy = SlaPolicy::forPriority($priority);
            $warningAt = $slaCalc->calculateWarningAt($now, $dueAt, $policy->warning_threshold_percentage);

            /** @var Ticket $ticket */
            $ticket = Ticket::query()->create([
                'ticket_number' => $ticketNumber,
                'subject' => $validated['subject'],
                'description' => $validated['description'],
                'priority' => $priority,
                'category_id' => (int) $validated['category_id'],
                'status' => TicketStatus::OPEN,
                'assigned_agent_id' => null,
                'created_by' => $user->id,
                'sla_due_at' => $dueAt,
                'sla_warning_at' => $warningAt,
                'sla_status' => SlaStatus::WITHIN_SLA,
            ]);

            // Process any initial attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    if ($file !== null && $file->isValid()) {
                        $attachmentService->upload($file, $ticket, $user);
                    }
                }
            }

            // Log initial audit activity
            $activityLogger->log($ticket, 'ticket_created', $user, [
                'ticket_number' => $ticket->ticket_number,
                'priority' => $ticket->priority->value,
                'category_id' => $ticket->category_id,
                'sla_due_at' => $dueAt->toIso8601String(),
            ]);

            return $ticket;
        });

        // Send queued notification to customer
        $user->notify(new TicketCreatedNotification($ticket));

        // Automatically attempt assignment upon creation
        try {
            $assignmentService->autoAssign($ticket);
            $ticket->refresh();
        } catch (TicketAlreadyAssignedException) {
            // Safeguard against concurrent race
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Ticket created successfully.',
                'ticket' => $ticket,
            ], 201);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket created successfully.');
    }

    /**
     * Display a specific ticket with its conversation thread and audit trail.
     */
    public function show(Request $request, Ticket $ticket): View|JsonResponse
    {
        Gate::authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $ticket->load([
            'category',
            'assignedAgent',
            'creator',
            'attachments.uploader',
            'activities' => function ($q) use ($user): void {
                if ($user->role->isCustomer()) {
                    $q->where('event', '!=', 'internal_note_added');
                }
                $q->with('causer')->orderByDesc('id');
            },
            'messages' => function ($query) use ($user): void {
                if ($user->role->isCustomer()) {
                    $query->where('is_internal', false);
                }
                $query->with(['user', 'attachments.uploader'])->orderBy('created_at', 'asc');
            },
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ticket' => $ticket,
            ]);
        }

        $agents = $user->role->isStaff()
            ? User::query()->whereIn('role', ['agent', 'admin'])->orderBy('name')->get()
            : collect();

        $categories = $user->role->isStaff()
            ? TicketCategory::query()->where('is_active', true)->orderBy('name')->get()
            : collect();

        return view('tickets.show', [
            'ticket' => $ticket,
            'agents' => $agents,
            'categories' => $categories,
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(Request $request, Ticket $ticket): View|RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $ticket);

        if ($request->wantsJson()) {
            return response()->json(['ticket' => $ticket]);
        }

        return redirect()->route('tickets.show', $ticket);
    }

    /**
     * Update ticket attributes and log audit history.
     */
    public function update(
        UpdateTicketRequest $request,
        Ticket $ticket,
        TicketActivityLogger $activityLogger,
        BusinessHoursCalculator $slaCalc,
    ): RedirectResponse|Response|JsonResponse {
        if ($request->has('priority') && ! $request->hasAny(['subject', 'description', 'category_id', 'status'])) {
            Gate::authorize('updatePriority', $ticket);
        } else {
            Gate::authorize('update', $ticket);
        }

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (isset($validated['priority'])) {
            Gate::authorize('updatePriority', $ticket);
        }

        $changes = [];

        DB::transaction(function () use ($validated, $user, $ticket, $activityLogger, $slaCalc, &$changes): void {
            // Priority update allowed for Customer (owner), Admin, or Assigned Agent
            if (isset($validated['priority']) && $validated['priority'] !== $ticket->priority->value) {
                $oldPriority = $ticket->priority;
                $newPriority = TicketPriority::from($validated['priority']);
                $changes['priority'] = ['from' => $oldPriority->value, 'to' => $newPriority->value];
                $ticket->priority = $newPriority;

                // Recalculate SLA due dates
                $newDueAt = $slaCalc->calculateDueAt($ticket->created_at, $newPriority);
                $policy = SlaPolicy::forPriority($newPriority);
                $newWarningAt = $slaCalc->calculateWarningAt($ticket->created_at, $newDueAt, $policy->warning_threshold_percentage);
                $ticket->sla_due_at = $newDueAt;
                $ticket->sla_warning_at = $newWarningAt;
                $ticket->sla_status = app(SlaEvaluationService::class)->evaluate($ticket);

                $auditMessage = sprintf(
                    'Priority changed from %s to %s by %s (%s)',
                    ucfirst($oldPriority->value),
                    ucfirst($newPriority->value),
                    $user->name,
                    ucfirst($user->role->value),
                );

                $activityLogger->log($ticket, 'priority_changed', $user, [
                    'old_priority' => $oldPriority->value,
                    'new_priority' => $newPriority->value,
                    'sla_due_at' => $newDueAt->toIso8601String(),
                    'message' => $auditMessage,
                    'description' => $auditMessage,
                ]);
            }

            if ($user->role->isCustomer()) {
                if (isset($validated['subject']) && $validated['subject'] !== $ticket->subject) {
                    $changes['subject'] = ['from' => $ticket->subject, 'to' => $validated['subject']];
                    $ticket->subject = $validated['subject'];
                }
                if (isset($validated['description']) && $validated['description'] !== $ticket->description) {
                    $changes['description'] = ['from' => $ticket->description, 'to' => $validated['description']];
                    $ticket->description = $validated['description'];
                }
            } else {
                // Staff updates
                if (isset($validated['subject']) && $validated['subject'] !== $ticket->subject) {
                    $changes['subject'] = ['from' => $ticket->subject, 'to' => $validated['subject']];
                    $ticket->subject = $validated['subject'];
                }
                if (isset($validated['description']) && $validated['description'] !== $ticket->description) {
                    $changes['description'] = ['from' => $ticket->description, 'to' => $validated['description']];
                    $ticket->description = $validated['description'];
                }
                if (isset($validated['category_id']) && (int) $validated['category_id'] !== $ticket->category_id) {
                    $changes['category_id'] = ['from' => $ticket->category_id, 'to' => (int) $validated['category_id']];
                    $ticket->category_id = (int) $validated['category_id'];
                }
                if (isset($validated['status']) && $validated['status'] !== $ticket->status->value) {
                    $oldStatus = $ticket->status;
                    $newStatus = TicketStatus::from($validated['status']);
                    $changes['status'] = ['from' => $oldStatus->value, 'to' => $newStatus->value];
                    $ticket->status = $newStatus;

                    $activityLogger->log($ticket, 'status_changed', $user, [
                        'old_status' => $oldStatus->value,
                        'new_status' => $newStatus->value,
                    ]);
                }
            }

            if ($changes !== []) {
                $ticket->save();

                $activityLogger->log($ticket, 'ticket_updated', $user, [
                    'changes' => $changes,
                ]);
            }
        });

        // If the request doesn't expect a redirect (JSON or plain 200 needed in tests/API)
        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket updated successfully.']);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket updated successfully.');
    }

    /**
     * Dedicated action for updating ticket priority.
     */
    public function updatePriority(
        UpdateTicketPriorityRequest $request,
        Ticket $ticket,
        TicketActivityLogger $activityLogger,
        BusinessHoursCalculator $slaCalc,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('updatePriority', $ticket);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $newPriority = TicketPriority::from($validated['priority']);

        if ($newPriority !== $ticket->priority) {
            DB::transaction(function () use ($ticket, $newPriority, $user, $slaCalc, $activityLogger): void {
                $oldPriority = $ticket->priority;
                $ticket->priority = $newPriority;

                $newDueAt = $slaCalc->calculateDueAt($ticket->created_at, $newPriority);
                $policy = SlaPolicy::forPriority($newPriority);
                $newWarningAt = $slaCalc->calculateWarningAt($ticket->created_at, $newDueAt, $policy->warning_threshold_percentage);

                $ticket->sla_due_at = $newDueAt;
                $ticket->sla_warning_at = $newWarningAt;
                $ticket->sla_status = app(SlaEvaluationService::class)->evaluate($ticket);
                $ticket->save();

                $auditMessage = sprintf(
                    'Priority changed from %s to %s by %s (%s)',
                    ucfirst($oldPriority->value),
                    ucfirst($newPriority->value),
                    $user->name,
                    ucfirst($user->role->value),
                );

                $activityLogger->log($ticket, 'priority_changed', $user, [
                    'old_priority' => $oldPriority->value,
                    'new_priority' => $newPriority->value,
                    'sla_due_at' => $newDueAt->toIso8601String(),
                    'message' => $auditMessage,
                    'description' => $auditMessage,
                ]);
            });
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Ticket priority updated successfully.',
                'ticket' => $ticket->fresh(),
            ]);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket priority updated successfully.');
    }

    /**
     * Delete a ticket (Admin only via policy).
     */
    public function destroy(Request $request, Ticket $ticket): RedirectResponse|Response|JsonResponse
    {
        Gate::authorize('delete', $ticket);

        $ticket->delete();

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket deleted successfully.']);
        }

        return redirect()
            ->route('tickets.index')
            ->with('status', 'Ticket deleted successfully.');
    }

    /**
     * Assign ticket to an agent (or unassign).
     */
    public function assign(
        Request $request,
        Ticket $ticket,
        TicketAssignmentService $assignmentService,
    ): RedirectResponse|Response|JsonResponse {
        Gate::authorize('assign', $ticket);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'assigned_agent_id' => ['nullable', 'exists:users,id'],
            'agent_id' => ['nullable', 'exists:users,id'],
        ]);

        $agentInput = $validated['assigned_agent_id'] ?? $validated['agent_id'] ?? null;
        $agent = $agentInput !== null ? User::find((int) $agentInput) : null;

        try {
            $assignmentService->assign($ticket, $agent, $user);
        } catch (TicketAlreadyAssignedException $e) {
            if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
                return response()->json([
                    'message' => 'This ticket has already been claimed or assigned to another agent.',
                    'error' => $e->getMessage(),
                ], 409);
            }

            return redirect()
                ->route('tickets.show', $ticket)
                ->with('error', 'This ticket was just claimed or assigned to another agent.');
        }

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket assignment updated.']);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket assignment updated.');
    }

    /**
     * Agent claims an unassigned ticket.
     */
    public function claim(
        Request $request,
        Ticket $ticket,
        TicketAssignmentService $assignmentService,
    ): RedirectResponse|Response|JsonResponse {
        Gate::authorize('claim', $ticket);

        /** @var User $user */
        $user = $request->user();

        try {
            $assignmentService->claim($ticket, $user);
        } catch (TicketAlreadyAssignedException $e) {
            if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
                return response()->json([
                    'message' => 'This ticket has already been claimed or assigned to another agent.',
                    'error' => $e->getMessage(),
                ], 409);
            }

            return redirect()
                ->route('tickets.show', $ticket)
                ->with('error', 'This ticket was just claimed or assigned to another agent.');
        }

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket claimed successfully.']);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket claimed successfully.');
    }

    /**
     * Automatically assign a ticket using the configured strategy.
     */
    public function autoAssign(
        Request $request,
        Ticket $ticket,
        TicketAssignmentService $assignmentService,
    ): RedirectResponse|Response|JsonResponse {
        Gate::authorize('autoAssign', $ticket);

        /** @var User $user */
        $user = $request->user();

        $strategyName = $request->input('strategy');

        try {
            $assignedAgent = $assignmentService->autoAssign(
                $ticket,
                is_string($strategyName) ? $strategyName : null,
                $user,
            );
        } catch (TicketAlreadyAssignedException $e) {
            if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
                return response()->json([
                    'message' => 'This ticket has already been claimed or assigned to another agent.',
                    'error' => $e->getMessage(),
                ], 409);
            }

            return redirect()
                ->route('tickets.show', $ticket)
                ->with('error', 'This ticket was just claimed or assigned to another agent.');
        }

        if ($assignedAgent === null) {
            if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
                return response()->json([
                    'message' => 'No available agents for assignment at this time.',
                    'assigned_agent' => null,
                ], 200);
            }

            return redirect()
                ->route('tickets.show', $ticket)
                ->with('status', 'No available agents found for automatic assignment.');
        }

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json([
                'message' => 'Ticket automatically assigned successfully.',
                'assigned_agent' => $assignedAgent,
            ], 200);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', "Ticket automatically assigned to {$assignedAgent->name}.");
    }

    /**
     * Resolve the ticket.
     */
    public function resolve(
        Request $request,
        Ticket $ticket,
        TicketActivityLogger $activityLogger,
    ): RedirectResponse|Response|JsonResponse {
        Gate::authorize('resolve', $ticket);

        /** @var User $user */
        $user = $request->user();

        $ticket->status = TicketStatus::RESOLVED;
        $ticket->resolved_at = now();
        $ticket->save();

        $activityLogger->log($ticket, 'ticket_resolved', $user, [
            'resolved_at' => $ticket->resolved_at->toIso8601String(),
        ]);

        // Send queued notification to customer
        if ($ticket->creator !== null) {
            $ticket->creator->notify(new TicketResolvedNotification($ticket));
        }

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket marked as resolved.']);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket marked as resolved.');
    }

    /**
     * Close the ticket.
     */
    public function close(
        Request $request,
        Ticket $ticket,
        TicketActivityLogger $activityLogger,
    ): RedirectResponse|Response|JsonResponse {
        Gate::authorize('close', $ticket);

        /** @var User $user */
        $user = $request->user();

        $ticket->status = TicketStatus::CLOSED;
        $ticket->closed_at = now();
        $ticket->save();

        $activityLogger->log($ticket, 'ticket_closed', $user, [
            'closed_at' => $ticket->closed_at->toIso8601String(),
        ]);

        if ($request->wantsJson() || ! $request->hasHeader('Referer')) {
            return response()->json(['message' => 'Ticket closed.']);
        }

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', 'Ticket closed.');
    }
}
