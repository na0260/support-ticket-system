<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ Auth::user()->role->isCustomer() ? __('My Tickets') : __('Ticket Management') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ Auth::user()->role->isCustomer() ? __('View and track your submitted support requests.') : __('Monitor, assign, and resolve support tickets across the organization.') }}
                </p>
            </div>
            @can('create', App\Models\Ticket::class)
                <a href="{{ route('tickets.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    <svg class="w-4 h-4 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    {{ __('New Ticket') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
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

            <!-- Navigation Tabs -->
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <a href="{{ route('tickets.index', array_merge(request()->except(['tab', 'page']), ['tab' => 'active'])) }}"
                       class="{{ $currentTab === 'active'
                            ? 'border-indigo-500 text-indigo-600 font-semibold'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                              whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span>{{ __('Active Tickets') }}</span>
                        <span class="{{ $currentTab === 'active' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }} py-0.5 px-2.5 rounded-full text-xs font-semibold">
                            {{ $activeCount }}
                        </span>
                    </a>

                    <a href="{{ route('tickets.index', array_merge(request()->except(['tab', 'page']), ['tab' => 'resolved_or_closed'])) }}"
                       class="{{ $currentTab === 'resolved_or_closed'
                            ? 'border-indigo-500 text-indigo-600 font-semibold'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                              whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span>{{ __('Resolved & Closed') }}</span>
                        <span class="{{ $currentTab === 'resolved_or_closed' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }} py-0.5 px-2.5 rounded-full text-xs font-semibold">
                            {{ $resolvedOrClosedCount }}
                        </span>
                    </a>
                </nav>
            </div>

            <!-- Search & Filter Card -->
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form action="{{ route('tickets.index') }}" method="GET" class="space-y-4">
                    <input type="hidden" name="tab" value="{{ $currentTab }}">

                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <!-- Search Keyword -->
                        <div class="md:col-span-2">
                            <label for="keyword" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Search') }}</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                </div>
                                <input type="text"
                                       name="keyword"
                                       id="keyword"
                                       value="{{ request('keyword', request('search', request('ticket_number'))) }}"
                                       placeholder="{{ __('Search subject, description, or ticket #...') }}"
                                       class="pl-9 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <!-- Category -->
                        <div>
                            <label for="category_id" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Category') }}</label>
                            <select name="category_id" id="category_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('All Categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Priority -->
                        <div>
                            <label for="priority" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Priority') }}</label>
                            <select name="priority" id="priority" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('All Priorities') }}</option>
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>
                                        {{ $priority->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label for="status" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Status') }}</label>
                            <select name="status" id="status" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('All Statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- SLA Status -->
                        <div>
                            <label for="sla_status" class="block text-xs font-medium text-gray-700 mb-1">{{ __('SLA Status') }}</label>
                            <select name="sla_status" id="sla_status" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('All SLA Statuses') }}</option>
                                @foreach ($slaStatuses as $slaStatus)
                                    <option value="{{ $slaStatus->value }}" @selected(request('sla_status') === $slaStatus->value)>
                                        {{ $slaStatus->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Assigned Agent (Staff Only) -->
                        @if (Auth::user()->role->isStaff())
                            <div>
                                <label for="assigned_agent_id" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Assigned Agent') }}</label>
                                <select name="assigned_agent_id" id="assigned_agent_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">{{ __('All Assignments') }}</option>
                                    <option value="unassigned" @selected(request('assigned_agent_id') === 'unassigned')>{{ __('Unassigned Only') }}</option>
                                    @foreach ($agents as $agent)
                                        <option value="{{ $agent->id }}" @selected((string) request('assigned_agent_id') === (string) $agent->id)>
                                            {{ $agent->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <!-- Date Range From -->
                        <div>
                            <label for="date_from" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Created From') }}</label>
                            <input type="date" name="date_from" id="date_from" value="{{ request('date_from') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <!-- Date Range To -->
                        <div>
                            <label for="date_to" class="block text-xs font-medium text-gray-700 mb-1">{{ __('Created To') }}</label>
                            <input type="date" name="date_to" id="date_to" value="{{ request('date_to') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <svg class="w-3.5 h-3.5 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                                {{ __('Apply Filters') }}
                            </button>

                            <a href="{{ route('tickets.index', ['tab' => $currentTab]) }}" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                {{ __('Reset') }}
                            </a>
                        </div>

                        <span class="text-xs text-gray-500">
                            {{ __('Showing') }} <span class="font-medium">{{ $tickets->total() }}</span> {{ __('matching ticket(s)') }}
                        </span>
                    </div>
                </form>

                <!-- Active Filter Badges -->
                @php
                    $filterParams = array_filter(request()->only(['keyword', 'category_id', 'priority', 'status', 'sla_status', 'assigned_agent_id', 'date_from', 'date_to']), fn ($v) => $v !== null && $v !== '');
                @endphp

                @if (!empty($filterParams))
                    <div class="mt-4 pt-3 border-t border-gray-100 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-medium text-gray-500">{{ __('Active Filters:') }}</span>

                        @if (!empty(request('keyword')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('Keyword: ') }} <strong>{{ request('keyword') }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['keyword', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('category_id')))
                            @php $catName = $categories->firstWhere('id', (int) request('category_id'))?->name ?? request('category_id'); @endphp
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('Category: ') }} <strong>{{ $catName }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['category_id', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('priority')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('Priority: ') }} <strong>{{ ucfirst(request('priority')) }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['priority', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('status')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('Status: ') }} <strong>{{ str_replace('_', ' ', ucfirst(request('status'))) }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['status', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('sla_status')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('SLA: ') }} <strong>{{ str_replace('_', ' ', ucfirst(request('sla_status'))) }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['sla_status', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('assigned_agent_id')))
                            @php
                                $agentLabel = request('assigned_agent_id') === 'unassigned'
                                    ? __('Unassigned')
                                    : ($agents->firstWhere('id', (int) request('assigned_agent_id'))?->name ?? request('assigned_agent_id'));
                            @endphp
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('Agent: ') }} <strong>{{ $agentLabel }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['assigned_agent_id', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('date_from')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('From: ') }} <strong>{{ request('date_from') }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['date_from', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        @if (!empty(request('date_to')))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200">
                                <span>{{ __('To: ') }} <strong>{{ request('date_to') }}</strong></span>
                                <a href="{{ route('tickets.index', array_merge(request()->except(['date_to', 'page']), ['tab' => $currentTab])) }}" class="hover:text-indigo-900 font-bold">&times;</a>
                            </span>
                        @endif

                        <a href="{{ route('tickets.index', ['tab' => $currentTab]) }}" class="text-xs text-red-600 hover:text-red-800 underline ml-2">
                            {{ __('Clear all') }}
                        </a>
                    </div>
                @endif
            </div>

            <!-- Tickets Table Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if ($tickets->isEmpty())
                    <div class="p-12 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-sm font-semibold text-gray-900">{{ __('No tickets found') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            @if (!empty($filterParams))
                                {{ __('No tickets match your filter criteria. Try adjusting or resetting filters.') }}
                            @else
                                {{ $currentTab === 'active' ? __('There are no active tickets at this time.') : __('No tickets in resolved or closed status.') }}
                            @endif
                        </p>
                        @can('create', App\Models\Ticket::class)
                            <div class="mt-6">
                                <a href="{{ route('tickets.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    {{ __('Submit a New Ticket') }}
                                </a>
                            </div>
                        @endcan
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Ticket #') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Subject & Category') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('SLA Due') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned To') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Created') }}</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">{{ __('View') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($tickets as $ticket)
                                    @php
                                        $priorityClasses = match($ticket->priority->color()) {
                                            'red' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
                                            'amber' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
                                            'blue' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-700/20',
                                            default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                                        };

                                        $statusClasses = match($ticket->status->color()) {
                                            'green' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
                                            'indigo' => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-700/20',
                                            'blue' => 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-700/20',
                                            'amber' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
                                            default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                                        };

                                        $slaClasses = match($ticket->sla_status->color()) {
                                            'green' => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                            'amber' => 'text-amber-800 bg-amber-50 border-amber-200',
                                            'red' => 'text-red-700 bg-red-50 border-red-200',
                                            default => 'text-gray-700 bg-gray-50 border-gray-200',
                                        };
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold font-mono text-indigo-600">
                                            <a href="{{ route('tickets.show', $ticket) }}" class="hover:underline">
                                                {{ $ticket->ticket_number }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate">
                                            <a href="{{ route('tickets.show', $ticket) }}" class="font-medium hover:text-indigo-600 transition-colors block truncate">
                                                {{ $ticket->subject }}
                                            </a>
                                            <span class="inline-block text-xs text-gray-500 mt-0.5">
                                                {{ $ticket->category->name }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium {{ $priorityClasses }}">
                                                {{ $ticket->priority->label() }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium {{ $statusClasses }}">
                                                {{ $ticket->status->label() }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                                            <div class="inline-flex items-center gap-1.5 px-2 py-1 rounded border {{ $slaClasses }}">
                                                <span class="font-medium">{{ $ticket->sla_status->label() }}</span>
                                            </div>
                                            @if ($ticket->sla_due_at)
                                                <div class="text-[11px] text-gray-500 mt-1">
                                                    {{ $ticket->sla_due_at->format('M d, H:i') }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            @if ($ticket->assignedAgent)
                                                <div class="flex items-center gap-1.5">
                                                    <div class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold">
                                                        {{ substr($ticket->assignedAgent->name, 0, 1) }}
                                                    </div>
                                                    <span>{{ $ticket->assignedAgent->name }}</span>
                                                </div>
                                            @else
                                                <div class="flex items-center gap-2">
                                                    <span class="italic text-gray-400">{{ __('Unassigned') }}</span>
                                                    @can('claim', $ticket)
                                                        <form action="{{ route('tickets.claim', $ticket) }}" method="POST" class="inline">
                                                            @csrf
                                                            <button type="submit" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 transition-colors">
                                                                {{ __('Claim') }}
                                                            </button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <time datetime="{{ $ticket->created_at->toIso8601String() }}" title="{{ $ticket->created_at->format('Y-m-d H:i:s') }}">
                                                {{ $ticket->created_at->diffForHumans() }}
                                            </time>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="{{ route('tickets.show', $ticket) }}" class="text-indigo-600 hover:text-indigo-900">
                                                {{ __('View') }} &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($tickets->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">
                            {{ $tickets->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
