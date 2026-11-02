<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Agent Workload Dashboard') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('Monitor support team capacity, active ticket queues, and SLA risk distribution.') }}
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">
                &larr; {{ __('Back to Dashboard') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 border-l-4 border-green-500 rounded-md text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <!-- Metric Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-indigo-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Total Support Agents') }}</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $metrics['total_agents'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('Configured staff accounts') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-emerald-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Available for Assignment') }}</div>
                    <div class="mt-2 text-3xl font-bold text-emerald-600">{{ $metrics['available_agents'] }}</div>
                    <div class="mt-1 text-xs text-emerald-600 font-medium">{{ __('Ready for auto-routing') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-sky-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Total Active Tickets') }}</div>
                    <div class="mt-2 text-3xl font-bold text-sky-600">{{ $metrics['total_active_tickets'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('Assigned across all agents') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-amber-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Avg Workload / Agent') }}</div>
                    <div class="mt-2 text-3xl font-bold text-amber-600">{{ $metrics['average_workload'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ __('Tickets per agent') }}</div>
                </div>
            </div>

            <!-- Agent Workload Table -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Agent Capacity & Assignment Queues') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Toggle agent availability to dynamically include or exclude them from automatic round-robin and workload assignment.') }}</p>
                    </div>
                </div>

                @if ($agents->isEmpty())
                    <div class="p-12 text-center text-sm text-gray-500">
                        {{ __('No support agents configured in the system.') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Agent') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Availability') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active Tickets') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Critical') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('At Risk SLA') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Resolved') }}</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($agents as $agent)
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-sm">
                                                    {{ substr($agent->name, 0, 1) }}
                                                </div>
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900">{{ $agent->name }}</div>
                                                    <div class="text-xs text-gray-500">{{ $agent->email }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <div class="flex items-center gap-2">
                                                @if ($agent->is_available)
                                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                                        {{ __('Available') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-500/20">
                                                        {{ __('Busy / Off') }}
                                                    </span>
                                                @endif

                                                <form action="{{ route('admin.agents.toggle_availability', $agent) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-[11px] px-2 py-0.5 rounded border {{ $agent->is_available ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' }}" title="Click to toggle availability">
                                                        {{ $agent->is_available ? __('Set Busy') : __('Set Available') }}
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold">
                                            <a href="{{ route('tickets.index', ['assigned_agent_id' => $agent->id, 'tab' => 'active']) }}" class="text-indigo-600 hover:underline">
                                                {{ $agent->active_tickets_count }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            @if ($agent->critical_tickets_count > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                    {{ $agent->critical_tickets_count }}
                                                </span>
                                            @else
                                                <span class="text-gray-400 text-xs">0</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            @if ($agent->at_risk_tickets_count > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                    {{ $agent->at_risk_tickets_count }}
                                                </span>
                                            @else
                                                <span class="text-gray-400 text-xs">0</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            {{ $agent->resolved_tickets_count }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium">
                                            <a href="{{ route('tickets.index', ['assigned_agent_id' => $agent->id, 'tab' => 'active']) }}" class="text-indigo-600 hover:text-indigo-900">
                                                {{ __('View Queue') }} &rarr;
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
