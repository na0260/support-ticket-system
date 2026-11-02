<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-mono font-bold text-sm text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                        {{ $ticket->ticket_number }}
                    </span>
                    <span class="text-xs text-gray-500">
                        &bull; {{ __('Created') }} {{ $ticket->created_at->diffForHumans() }} {{ __('by') }} <strong class="text-gray-700">{{ $ticket->creator->name }}</strong>
                    </span>
                </div>
                <h2 class="font-bold text-2xl text-gray-900 leading-tight mt-1">
                    {{ $ticket->subject }}
                </h2>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('tickets.index') }}" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    &larr; {{ __('Back to Tickets') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 border-l-4 border-green-500 rounded-md text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="p-4 bg-red-50 border-l-4 border-red-500 rounded-md text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Main Column: Timeline, Description & Conversation (2/3) -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Original Description Card -->
                    <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden border border-gray-200">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-sm">
                                    {{ substr($ticket->creator->name, 0, 1) }}
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">{{ $ticket->creator->name }}</h3>
                                    <p class="text-xs text-gray-500">{{ __('Ticket Opener') }} &bull; {{ $ticket->created_at->format('M d, Y H:i') }}</p>
                                </div>
                            </div>
                            <span class="text-xs px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 font-medium">
                                {{ __('Original Issue') }}
                            </span>
                        </div>
                        <div class="p-6 text-gray-800 text-sm whitespace-pre-line leading-relaxed">
                            {{ $ticket->description }}
                        </div>

                        <!-- Ticket Initial Attachments -->
                        @if ($ticket->attachments->isNotEmpty())
                            <div class="px-6 py-3 bg-gray-50 border-t border-gray-200">
                                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">{{ __('Attachments') }} ({{ $ticket->attachments->count() }})</h4>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($ticket->attachments as $attachment)
                                        <a href="{{ route('ticket_attachments.download', $attachment) }}"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-white border border-gray-300 text-indigo-700 hover:bg-indigo-50 transition-colors shadow-sm">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                            </svg>
                                            <span class="truncate max-w-[180px]">{{ $attachment->file_name }}</span>
                                            <span class="text-gray-400 text-[10px]">({{ number_format($attachment->file_size / 1024, 1) }} KB)</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Conversation Thread -->
                    <div class="space-y-4">
                        <div class="relative flex items-center justify-center">
                            <div class="border-t border-gray-200 absolute inset-0"></div>
                            <span class="relative bg-gray-100 px-4 text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Conversation Thread') }} ({{ $ticket->messages->count() }})
                            </span>
                        </div>

                        @foreach ($ticket->messages as $message)
                            @php
                                $isInternal = $message->is_internal;
                                $isAgent = $message->user->role->isStaff();
                            @endphp

                            <div class="sm:rounded-lg overflow-hidden border shadow-sm transition-all
                                @if ($isInternal)
                                    bg-amber-50/70 border-amber-300 ring-1 ring-amber-400/30
                                @elseif ($isAgent)
                                    bg-white border-indigo-200
                                @else
                                    bg-white border-gray-200
                                @endif
                            ">
                                <!-- Message Header -->
                                <div class="px-5 py-3 border-b flex items-center justify-between
                                    @if ($isInternal)
                                        bg-amber-100/60 border-amber-200 text-amber-900
                                    @elseif ($isAgent)
                                        bg-indigo-50/50 border-indigo-100
                                    @else
                                        bg-gray-50 border-gray-100
                                    @endif
                                ">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                            @if ($isInternal)
                                                bg-amber-600 text-white
                                            @elseif ($isAgent)
                                                bg-indigo-600 text-white
                                            @else
                                                bg-gray-600 text-white
                                            @endif
                                        ">
                                            {{ substr($message->user->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <span class="text-sm font-semibold text-gray-900">{{ $message->user->name }}</span>
                                            <span class="text-xs text-gray-500 ms-1.5">&bull; {{ $message->created_at->diffForHumans() }}</span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        @if ($isInternal)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded text-xs font-bold bg-amber-200 text-amber-900 uppercase tracking-wide border border-amber-300">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                </svg>
                                                [INTERNAL NOTE - HIDDEN FROM CUSTOMER]
                                            </span>
                                        @elseif ($isAgent)
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-indigo-100 text-indigo-700">
                                                {{ __('Support Staff') }}
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-600">
                                                {{ __('Customer') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Message Body -->
                                <div class="p-5 text-sm whitespace-pre-line leading-relaxed
                                    @if ($isInternal) text-amber-950 @else text-gray-800 @endif
                                ">
                                    {{ $message->body }}
                                </div>

                                <!-- Message Attachments -->
                                @if ($message->attachments->isNotEmpty())
                                    <div class="px-5 py-2.5 border-t border-gray-100 bg-gray-50/50 flex flex-wrap gap-2">
                                        @foreach ($message->attachments as $attachment)
                                            <a href="{{ route('ticket_attachments.download', $attachment) }}"
                                               class="inline-flex items-center gap-1.5 px-3 py-1 rounded text-xs font-medium bg-white border border-gray-300 text-indigo-700 hover:bg-indigo-50 transition-colors shadow-sm">
                                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                                </svg>
                                                <span class="truncate max-w-[160px]">{{ $attachment->file_name }}</span>
                                                <span class="text-gray-400 text-[10px]">({{ number_format($attachment->file_size / 1024, 1) }} KB)</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <!-- Reply / Action Box -->
                    @if ($ticket->status === \App\Enums\TicketStatus::CLOSED)
                        <div class="p-6 bg-gray-50 border border-gray-200 rounded-lg text-center text-sm text-gray-600">
                            <svg class="mx-auto h-8 w-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <p class="font-medium text-gray-700">{{ __('This ticket is closed.') }}</p>
                            <p class="text-xs text-gray-500 mt-1">{{ __('New replies cannot be posted to a permanently closed ticket. Please submit a new ticket if you require further assistance.') }}</p>
                        </div>
                    @else
                        @can('create', [App\Models\TicketMessage::class, $ticket])
                            <div x-data="{ tab: 'public', isInternal: false }" class="bg-white shadow-sm sm:rounded-lg border border-gray-200 overflow-hidden">
                                <!-- Staff Mode Selector Tabs -->
                                @if (Auth::user()->role->isStaff())
                                    <div class="flex border-b border-gray-200 bg-gray-50">
                                        <button type="button" @click="tab = 'public'; isInternal = false"
                                                :class="tab === 'public' ? 'bg-white border-b-2 border-indigo-600 font-semibold text-indigo-600' : 'text-gray-500 hover:text-gray-700'"
                                                class="px-5 py-3 text-sm focus:outline-none transition-colors flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                            </svg>
                                            <span>{{ __('Public Reply') }}</span>
                                        </button>
                                        <button type="button" @click="tab = 'internal'; isInternal = true"
                                                :class="tab === 'internal' ? 'bg-amber-50 border-b-2 border-amber-600 font-semibold text-amber-900' : 'text-gray-500 hover:text-gray-700'"
                                                class="px-5 py-3 text-sm focus:outline-none transition-colors flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                            <span>{{ __('Internal Note (Staff Only)') }}</span>
                                        </button>
                                    </div>
                                @endif

                                <form action="{{ route('ticket_messages.store', $ticket) }}" method="POST" enctype="multipart/form-data" class="p-6 space-y-4"
                                      :class="isInternal ? 'bg-amber-50/40' : 'bg-white'">
                                    @csrf

                                    <input type="hidden" name="is_internal" :value="isInternal ? '1' : '0'">

                                    @if ($ticket->isResolved() && Auth::user()->role->isCustomer())
                                        <div class="p-3 bg-amber-50 border-l-4 border-amber-400 text-xs text-amber-800 rounded">
                                            {{ __('Notice: Sending a reply will automatically reopen this ticket for further assistance.') }}
                                        </div>
                                    @endif

                                    <div x-show="isInternal" class="p-3 bg-amber-100 border border-amber-300 rounded text-xs text-amber-900 font-medium flex items-center gap-2">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <span>{{ __('Internal notes are strictly confidential and will never be visible to the customer.') }}</span>
                                    </div>

                                    <div>
                                        <label for="body" class="sr-only">{{ __('Reply') }}</label>
                                        <textarea id="body" name="body" rows="4" required minlength="2"
                                                  class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                                  :placeholder="isInternal ? 'Type an internal note visible to staff only...' : 'Write your reply...'"></textarea>
                                        <x-input-error class="mt-2" :messages="$errors->get('body')" />
                                    </div>

                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2">
                                        <div>
                                            <label for="message_attachments" class="inline-flex items-center gap-1.5 text-xs text-gray-600 hover:text-indigo-600 cursor-pointer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                                </svg>
                                                <span>{{ __('Attach files (Max 10MB each)') }}</span>
                                                <input id="message_attachments" name="attachments[]" type="file" multiple class="sr-only">
                                            </label>
                                            <x-input-error class="mt-1" :messages="$errors->get('attachments.*')" />
                                        </div>

                                        <button type="submit"
                                                :class="isInternal ? 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500' : 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500'"
                                                class="inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150">
                                            <span x-text="isInternal ? '{{ __('Add Internal Note') }}' : '{{ __('Post Reply') }}'"></span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endcan
                    @endif

                </div>

                <!-- Sidebar Column: Metadata, SLA, Actions, Audit Trail (1/3) -->
                <div class="space-y-6">

                    <!-- Ticket Overview Card -->
                    <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200 p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider border-b border-gray-100 pb-2">
                            {{ __('Ticket Details') }}
                        </h3>

                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('Status') }}</dt>
                                <dd>
                                    @php
                                        $statusBadge = match($ticket->status->color()) {
                                            'green' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
                                            'indigo' => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-700/20',
                                            'blue' => 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-700/20',
                                            'amber' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
                                            default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $statusBadge }}">
                                        {{ $ticket->status->label() }}
                                    </span>
                                </dd>
                            </div>

                            <div class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('Priority') }}</dt>
                                <dd>
                                    @php
                                        $priorityBadge = match($ticket->priority->color()) {
                                            'red' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
                                            'amber' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
                                            'blue' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-700/20',
                                            default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $priorityBadge }}">
                                        {{ $ticket->priority->label() }}
                                    </span>
                                </dd>
                            </div>

                            <div class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('Category') }}</dt>
                                <dd class="font-medium text-gray-900">{{ $ticket->category->name }}</dd>
                            </div>

                            <div class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('Customer') }}</dt>
                                <dd class="font-medium text-gray-900">{{ $ticket->creator->name }}</dd>
                            </div>

                            <div class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('Created At') }}</dt>
                                <dd class="text-xs text-gray-700">{{ $ticket->created_at->format('M d, Y H:i T') }}</dd>
                            </div>

                            @if ($ticket->resolved_at)
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">{{ __('Resolved At') }}</dt>
                                    <dd class="text-xs text-emerald-700 font-medium">{{ $ticket->resolved_at->format('M d, Y H:i T') }}</dd>
                                </div>
                            @endif

                            @if ($ticket->closed_at)
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">{{ __('Closed At') }}</dt>
                                    <dd class="text-xs text-gray-700 font-medium">{{ $ticket->closed_at->format('M d, Y H:i T') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <!-- SLA Management Card -->
                    <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200 p-6 space-y-4">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">
                                {{ __('SLA Target') }}
                            </h3>
                            @php
                                $slaBadge = match($ticket->sla_status->color()) {
                                    'green' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    'amber' => 'bg-amber-50 text-amber-800 border-amber-200',
                                    'red' => 'bg-red-50 text-red-700 border-red-200',
                                    default => 'bg-gray-50 text-gray-700 border-gray-200',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold border {{ $slaBadge }}">
                                {{ $ticket->sla_status->label() }}
                            </span>
                        </div>

                        <dl class="space-y-2 text-xs">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('Target SLA Window') }}:</dt>
                                <dd class="font-semibold text-gray-800">{{ $ticket->priority->slaHours() }} {{ __('business hours') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('Due At') }}:</dt>
                                <dd class="font-mono text-gray-900">{{ $ticket->sla_due_at?->format('M d, Y H:i') ?? 'N/A' }}</dd>
                            </div>
                            @if ($ticket->sla_warning_at)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">{{ __('Warning Threshold (80%)') }}:</dt>
                                    <dd class="font-mono text-gray-600">{{ $ticket->sla_warning_at->format('M d, Y H:i') }}</dd>
                                </div>
                            @endif
                            @if ($ticket->sla_due_at && $ticket->status->isActive())
                                <div class="mt-2 pt-2 border-t text-[11px] text-gray-500 flex justify-between">
                                    <span>{{ __('Target Difference') }}:</span>
                                    <span class="font-medium {{ now()->isAfter($ticket->sla_due_at) ? 'text-red-600 font-bold' : 'text-gray-700' }}">
                                        {{ $ticket->sla_due_at->diffForHumans() }}
                                    </span>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <!-- Assigned Agent Card -->
                    <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200 p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider border-b border-gray-100 pb-2">
                            {{ __('Assigned Agent') }}
                        </h3>

                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-sm">
                                {{ $ticket->assignedAgent ? substr($ticket->assignedAgent->name, 0, 1) : '?' }}
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-gray-900">
                                    {{ $ticket->assignedAgent?->name ?? __('Unassigned') }}
                                </h4>
                                <p class="text-xs text-gray-500">
                                    {{ $ticket->assignedAgent?->email ?? __('No agent assigned yet') }}
                                </p>
                            </div>
                        </div>

                        <!-- Staff Claim Button -->
                        @can('claim', $ticket)
                            <form action="{{ route('tickets.claim', $ticket) }}" method="POST" class="pt-2">
                                @csrf
                                <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-indigo-50 border border-indigo-200 rounded-md font-semibold text-xs text-indigo-700 hover:bg-indigo-100 transition-colors">
                                    {{ __('Claim This Ticket') }}
                                </button>
                            </form>
                        @endcan

                        <!-- Staff Assign to Agent Form -->
                        @can('assign', $ticket)
                            @if ($agents->isNotEmpty())
                                <form action="{{ route('tickets.assign', $ticket) }}" method="POST" class="pt-2 space-y-2 border-t border-gray-100">
                                    @csrf
                                    <label for="assigned_agent_id" class="block text-xs font-medium text-gray-700">{{ __('Reassign Agent') }}</label>
                                    <div class="flex gap-2">
                                        <select id="assigned_agent_id" name="assigned_agent_id" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md text-xs shadow-sm">
                                            <option value="">{{ __('Unassign') }}</option>
                                            @foreach ($agents as $agent)
                                                <option value="{{ $agent->id }}" @selected($ticket->assigned_agent_id === $agent->id)>
                                                    {{ $agent->name }} ({{ ucfirst($agent->role->value) }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="px-3 py-1.5 bg-gray-800 text-white rounded-md text-xs font-semibold hover:bg-gray-700">
                                            {{ __('Save') }}
                                        </button>
                                    </div>
                                </form>
                            @endif
                        @endcan
                    </div>

                    <!-- Ticket Lifecycle Actions (Resolve / Close) -->
                    @if ($ticket->status !== \App\Enums\TicketStatus::CLOSED)
                        <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200 p-6 space-y-3">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider border-b border-gray-100 pb-2">
                                {{ __('Lifecycle Actions') }}
                            </h3>

                            @can('resolve', $ticket)
                                <form action="{{ route('tickets.resolve', $ticket) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest transition-colors shadow-sm">
                                        {{ __('Mark as Resolved') }}
                                    </button>
                                </form>
                            @endcan

                            @can('close', $ticket)
                                <form action="{{ route('tickets.close', $ticket) }}" method="POST" onsubmit="return confirm('Are you sure you want to permanently close this ticket?');">
                                    @csrf
                                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-gray-800 hover:bg-gray-900 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest transition-colors shadow-sm">
                                        {{ __('Close Ticket Permanently') }}
                                    </button>
                                </form>
                            @endcan
                        </div>
                    @endif

                    <!-- Audit Trail / Activity Stream -->
                    <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200 p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider border-b border-gray-100 pb-2">
                            {{ __('Activity Log') }}
                        </h3>

                        <div class="flow-root">
                            <ul role="list" class="-mb-6">
                                @forelse ($ticket->activities as $activity)
                                    <li>
                                        <div class="relative pb-6">
                                            @if (! $loop->last)
                                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                            @endif
                                            <div class="relative flex space-x-3">
                                                <div>
                                                    <span class="h-8 w-8 rounded-full bg-indigo-50 border border-indigo-200 flex items-center justify-center text-xs font-semibold text-indigo-700">
                                                        {{ substr($activity->causer?->name ?? 'S', 0, 1) }}
                                                    </span>
                                                </div>
                                                <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                                    <div>
                                                        <p class="text-xs text-gray-800 font-medium">
                                                            {{ ucwords(str_replace('_', ' ', $activity->event)) }}
                                                        </p>
                                                        <p class="text-[11px] text-gray-500">
                                                            {{ __('By') }} {{ $activity->causer?->name ?? __('System') }}
                                                        </p>
                                                    </div>
                                                    <div class="text-right text-[11px] whitespace-nowrap text-gray-400">
                                                        <time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->diffForHumans(short: true) }}</time>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                @empty
                                    <li class="text-xs text-gray-500 italic">{{ __('No activities recorded yet.') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </div>
</x-app-layout>
