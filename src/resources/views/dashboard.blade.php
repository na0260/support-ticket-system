<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ $is_staff ? __('Support Operations Dashboard') : __('Customer Support Dashboard') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $is_staff ? __('Real-time queue monitoring, SLA compliance, and workload distribution.') : __('Track and manage your submitted support tickets and inquiries.') }}
                </p>
            </div>
            <div>
                @can('create', App\Models\Ticket::class)
                    <a href="{{ route('tickets.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        <svg class="w-4 h-4 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        {{ __('New Ticket') }}
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($is_staff)
                <!-- STAFF METRICS VIEW -->
                <!-- KPI Stat Cards -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <!-- Total Active -->
                    <a href="{{ route('tickets.index', ['tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-indigo-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active Tickets') }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900">{{ $kpis['total_active'] }}</div>
                        <div class="mt-1 text-xs text-indigo-600 hover:underline flex items-center gap-1">
                            <span>{{ __('View queue') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Unassigned -->
                    <a href="{{ route('tickets.index', ['assigned_agent_id' => 'unassigned', 'tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-sky-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Unassigned') }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900">{{ $kpis['unassigned'] }}</div>
                        <div class="mt-1 text-xs text-sky-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Claim tickets') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Critical Priority -->
                    <a href="{{ route('tickets.index', ['priority' => 'critical', 'tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-rose-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Critical') }}</div>
                        <div class="mt-2 text-2xl font-bold text-rose-600">{{ $kpis['critical'] }}</div>
                        <div class="mt-1 text-xs text-rose-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Urgent attention') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Approaching Breach -->
                    <a href="{{ route('tickets.index', ['sla_status' => 'approaching_breach', 'tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-amber-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('At Risk SLA') }}</div>
                        <div class="mt-2 text-2xl font-bold text-amber-600">{{ $kpis['approaching_breach'] }}</div>
                        <div class="mt-1 text-xs text-amber-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Approaching') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- SLA Breached -->
                    <a href="{{ route('tickets.index', ['sla_status' => 'breached', 'tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-red-600 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('SLA Breached') }}</div>
                        <div class="mt-2 text-2xl font-bold text-red-600">{{ $kpis['breached'] }}</div>
                        <div class="mt-1 text-xs text-red-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Action required') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Resolved Today / Total Resolved -->
                    <a href="{{ route('tickets.index', ['tab' => 'resolved_or_closed', 'status' => 'resolved']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-emerald-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Resolved Today') }}</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-600">{{ $kpis['resolved_today'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">
                            <span>{{ $kpis['total_resolved'] }} {{ __('all-time') }}</span>
                        </div>
                    </a>
                </div>

                <!-- Distributions Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Priority Distribution -->
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">{{ __('Active by Priority') }}</h3>
                        <div class="space-y-3">
                            @php
                                $totalPrio = max(1, array_sum($priority_distribution));
                                $prioColors = [
                                    'critical' => ['bar' => 'bg-red-500', 'badge' => 'text-red-700 bg-red-50'],
                                    'high' => ['bar' => 'bg-amber-500', 'badge' => 'text-amber-700 bg-amber-50'],
                                    'medium' => ['bar' => 'bg-blue-500', 'badge' => 'text-blue-700 bg-blue-50'],
                                    'low' => ['bar' => 'bg-gray-400', 'badge' => 'text-gray-700 bg-gray-50'],
                                ];
                            @endphp
                            @foreach ($priority_distribution as $prioKey => $prioCount)
                                @php
                                    $prioPercent = round(($prioCount / $totalPrio) * 100);
                                    $style = $prioColors[$prioKey] ?? ['bar' => 'bg-gray-400', 'badge' => 'text-gray-700 bg-gray-50'];
                                @endphp
                                <div>
                                    <div class="flex justify-between items-center text-xs mb-1">
                                        <a href="{{ route('tickets.index', ['priority' => $prioKey, 'tab' => 'active']) }}" class="font-medium text-gray-700 hover:text-indigo-600 capitalize">
                                            {{ $prioKey }}
                                        </a>
                                        <span class="font-semibold text-gray-900">{{ $prioCount }} ({{ $prioPercent }}%)</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="{{ $style['bar'] }} h-2 rounded-full" style="width: {{ $prioPercent }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Category Distribution -->
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">{{ __('Active by Category') }}</h3>
                        @if (empty($category_distribution))
                            <p class="text-xs text-gray-500 italic">{{ __('No active tickets by category.') }}</p>
                        @else
                            <div class="space-y-3">
                                @php
                                    $totalCat = max(1, array_sum($category_distribution));
                                @endphp
                                @foreach ($category_distribution as $catName => $catCount)
                                    @php
                                        $catPercent = round(($catCount / $totalCat) * 100);
                                    @endphp
                                    <div>
                                        <div class="flex justify-between items-center text-xs mb-1">
                                            <span class="font-medium text-gray-700 truncate max-w-[180px]">{{ $catName }}</span>
                                            <span class="font-semibold text-gray-900">{{ $catCount }} ({{ $catPercent }}%)</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ $catPercent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Team Capacity Distribution --}}
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">{{ __('Team Capacity') }}</h3>
                        @if ($agent_workload->isEmpty())
                            <p class="text-xs text-gray-500 italic">{{ __('No support agents available.') }}</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($agent_workload as $agent)
                                    <div class="flex items-center justify-between text-xs py-1 border-b border-gray-100 last:border-0">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-[11px]">
                                                {{ substr($agent->name, 0, 1) }}
                                            </div>
                                            <a href="{{ route('tickets.index', ['assigned_agent_id' => $agent->id, 'tab' => 'active']) }}" class="font-medium text-gray-800 hover:text-indigo-600">
                                                {{ $agent->name }}
                                            </a>
                                        </div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $agent->assigned_tickets_count > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-500' }}">
                                            {{ $agent->assigned_tickets_count }} {{ __('active') }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

            @else
                <!-- CUSTOMER METRICS VIEW -->
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <!-- Total Active -->
                    <a href="{{ route('tickets.index', ['tab' => 'active']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-indigo-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active Tickets') }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900">{{ $kpis['total_active'] }}</div>
                        <div class="mt-1 text-xs text-indigo-600 hover:underline flex items-center gap-1">
                            <span>{{ __('View active') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Open -->
                    <a href="{{ route('tickets.index', ['status' => 'open']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-blue-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Awaiting Response') }}</div>
                        <div class="mt-2 text-2xl font-bold text-blue-600">{{ $kpis['open_count'] }}</div>
                        <div class="mt-1 text-xs text-blue-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Open queue') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- In Progress -->
                    <a href="{{ route('tickets.index', ['status' => 'in_progress']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-amber-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('In Progress') }}</div>
                        <div class="mt-2 text-2xl font-bold text-amber-600">{{ $kpis['in_progress_count'] }}</div>
                        <div class="mt-1 text-xs text-amber-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Being handled') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Waiting on Customer -->
                    <a href="{{ route('tickets.index', ['status' => 'waiting_customer']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-purple-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Action Needed') }}</div>
                        <div class="mt-2 text-2xl font-bold text-purple-600">{{ $kpis['waiting_customer_count'] }}</div>
                        <div class="mt-1 text-xs text-purple-600 hover:underline flex items-center gap-1">
                            <span>{{ __('Needs your reply') }}</span> &rarr;
                        </div>
                    </a>

                    <!-- Resolved / Closed -->
                    <a href="{{ route('tickets.index', ['tab' => 'resolved_or_closed']) }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-emerald-500 hover:shadow-md transition">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Resolved') }}</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-600">{{ $kpis['resolved_count'] + $kpis['closed_count'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">
                            <span>{{ $kpis['total_tickets'] }} {{ __('total submitted') }}</span>
                        </div>
                    </a>
                </div>
            @endif

            <!-- 5 Most Recent Tickets Table -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Recent Tickets') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Showing the 5 most recent tickets.') }}</p>
                    </div>
                    <a href="{{ route('tickets.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">
                        {{ __('View All Tickets') }} &rarr;
                    </a>
                </div>

                @if ($recent_tickets->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-500">
                        {{ __('No recent tickets found.') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Ticket #') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Subject') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('SLA') }}</th>
                                    @if ($is_staff)
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned To') }}</th>
                                    @endif
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Created') }}</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">{{ __('View') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($recent_tickets as $ticket)
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
                                            <span class="inline-flex items-center px-2 py-0.5 rounded border {{ $slaClasses }}">
                                                {{ $ticket->sla_status->label() }}
                                            </span>
                                        </td>
                                        @if ($is_staff)
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                {{ $ticket->assignedAgent?->name ?? __('Unassigned') }}
                                            </td>
                                        @endif
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $ticket->created_at->diffForHumans() }}
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
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
