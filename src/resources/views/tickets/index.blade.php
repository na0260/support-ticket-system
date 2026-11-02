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
                    <a href="{{ route('tickets.index', ['tab' => 'active']) }}"
                       class="{{ $currentTab === 'active'
                            ? 'border-indigo-500 text-indigo-600 font-semibold'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                              whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span>{{ __('Active Tickets') }}</span>
                        <span class="{{ $currentTab === 'active' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }} py-0.5 px-2.5 rounded-full text-xs font-semibold">
                            {{ $activeCount }}
                        </span>
                    </a>

                    <a href="{{ route('tickets.index', ['tab' => 'resolved_or_closed']) }}"
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

            <!-- Tickets Table Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if ($tickets->isEmpty())
                    <div class="p-12 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-sm font-semibold text-gray-900">{{ __('No tickets found') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $currentTab === 'active' ? __('There are no active tickets at this time.') : __('No tickets in resolved or closed status.') }}
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
                                                <span class="italic text-gray-400">{{ __('Unassigned') }}</span>
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
