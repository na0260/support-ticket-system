<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('My Ticket Queue') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('Your personally assigned active support tickets, prioritized by SLA due date.') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('agent.unassigned') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-xs font-semibold hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    {{ __('Claim Unassigned Tickets') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 border-l-4 border-green-500 rounded-md text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <!-- Agent Personal Metrics -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-indigo-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('My Active Queue') }}</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $metrics['active_count'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('Assigned to you') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-amber-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Approaching Breach') }}</div>
                    <div class="mt-2 text-3xl font-bold text-amber-600">{{ $metrics['approaching_breach_count'] }}</div>
                    <div class="mt-1 text-xs text-amber-600 font-medium">{{ __('Nearing SLA limit') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-red-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('SLA Breached') }}</div>
                    <div class="mt-2 text-3xl font-bold text-red-600">{{ $metrics['breached_count'] }}</div>
                    <div class="mt-1 text-xs text-red-600 font-medium">{{ __('Immediate triage needed') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-emerald-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Resolved by Me') }}</div>
                    <div class="mt-2 text-3xl font-bold text-emerald-600">{{ $metrics['resolved_count'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ __('Total resolved') }}</div>
                </div>
            </div>

            <!-- Tickets Table Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Tickets Requiring Action') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Showing active tickets assigned to your account.') }}</p>
                    </div>
                </div>

                @if ($tickets->isEmpty())
                    <div class="p-12 text-center text-sm text-gray-500">
                        <svg class="mx-auto h-12 w-12 text-emerald-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Your queue is clear!') }}</h3>
                        <p class="mt-1 text-xs text-gray-500">{{ __('Check the Unassigned Queue to pick up new tickets and assist customers.') }}</p>
                        <div class="mt-4">
                            <a href="{{ route('agent.unassigned') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-xs font-semibold hover:bg-indigo-700 transition">
                                {{ __('Browse Unassigned Tickets') }} &rarr;
                            </a>
                        </div>
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
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Customer') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Last Updated') }}</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
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
                                            'amber' => 'text-amber-800 bg-amber-50 border-amber-200 animate-pulse',
                                            'red' => 'text-red-700 bg-red-50 border-red-200 font-bold',
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
                                            <span class="inline-block text-xs text-gray-500">
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
                                                <span>{{ $ticket->sla_status->label() }}</span>
                                            </div>
                                            @if ($ticket->sla_due_at)
                                                <div class="text-[11px] text-gray-500 mt-1 font-mono">
                                                    {{ $ticket->sla_due_at->diffForHumans() }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            {{ $ticket->creator->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $ticket->updated_at->diffForHumans() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="{{ route('tickets.show', $ticket) }}" class="inline-flex items-center px-3 py-1.5 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 rounded-md text-xs font-semibold transition">
                                                {{ __('View & Reply') }} &rarr;
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
