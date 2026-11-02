<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('imports.index') }}" class="text-gray-400 hover:text-gray-600 transition">
                    &larr; {{ __('All Imports') }}
                </a>
                <span class="text-gray-300">|</span>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Import Batch #') }}{{ $import->id }}
                </h2>
                @php
                    $statusBadge = match($import->status) {
                        'completed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
                        'processing' => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20 animate-pulse',
                        'failed' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
                        default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                    };
                @endphp
                <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold uppercase {{ $statusBadge }}">
                    {{ $import->status }}
                </span>
            </div>
            <div>
                @if ($import->hasFailures())
                    <a href="{{ route('imports.failures', $import) }}" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition">
                        <svg class="w-4 h-4 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        {{ __('Download Failed Rows (CSV)') }}
                    </a>
                @endif
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

            <!-- Batch Overview Metrics -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-indigo-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Total Rows') }}</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $import->total_rows }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ $import->file_name }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-sky-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Processed') }}</div>
                    <div class="mt-2 text-3xl font-bold text-sky-600">{{ $import->processed_rows }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ $import->progressPercentage() }}% {{ __('completed') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-emerald-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Successful') }}</div>
                    <div class="mt-2 text-3xl font-bold text-emerald-600">{{ $import->successful_rows }}</div>
                    <div class="mt-1 text-xs text-emerald-600 font-medium">{{ __('Tickets created with SLA') }}</div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5 border-l-4 border-red-500">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Failed Rows') }}</div>
                    <div class="mt-2 text-3xl font-bold text-red-600">{{ $import->failed_rows }}</div>
                    <div class="mt-1 text-xs text-red-500">{{ __('Logged for review below') }}</div>
                </div>
            </div>

            @if ($import->isProcessing())
                <div class="bg-indigo-50 border-l-4 border-indigo-500 p-4 rounded-md text-sm text-indigo-700 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ __('This import is currently processing in the background. Refresh this page to track updated progress.') }}</span>
                    </div>
                    <button onclick="window.location.reload()" class="px-3 py-1 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700">
                        {{ __('Refresh Now') }}
                    </button>
                </div>
            @endif

            <!-- Failed Rows Breakdown Section -->
            @if ($import->hasFailures())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('Failed Rows Breakdown') }}</h3>
                            <p class="text-xs text-gray-500">{{ __('Showing row-by-row validation errors and raw CSV input values.') }}</p>
                        </div>
                        <a href="{{ route('imports.failures', $import) }}" class="inline-flex items-center text-xs font-semibold text-red-600 hover:text-red-900">
                            <svg class="w-3.5 h-3.5 me-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            {{ __('Export Errors (CSV)') }}
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">{{ __('Row #') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Validation Errors') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Raw CSV Data') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($failures as $failure)
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono font-bold text-gray-900">
                                            {{ $failure->row_number }}
                                        </td>
                                        <td class="px-6 py-4 text-xs">
                                            <div class="space-y-1">
                                                @foreach ((array) $failure->errors as $field => $messages)
                                                    <div class="p-1.5 bg-red-50 border border-red-200 rounded text-red-800">
                                                        <strong class="capitalize">{{ str_replace('_', ' ', $field) }}:</strong>
                                                        @if (is_array($messages))
                                                            {{ implode(', ', $messages) }}
                                                        @else
                                                            {{ $messages }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-xs font-mono text-gray-600 bg-gray-50/50">
                                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
                                                @foreach ((array) $failure->raw_data as $key => $val)
                                                    <div>
                                                        <dt class="inline font-semibold text-gray-500">{{ $key }}:</dt>
                                                        <dd class="inline text-gray-800">{{ is_scalar($val) ? $val : json_encode($val) }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($failures->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">
                            {{ $failures->links() }}
                        </div>
                    @endif
                </div>
            @elseif ($import->isCompleted())
                <div class="bg-white p-8 text-center rounded-lg shadow-sm">
                    <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Flawless Import') }}</h3>
                    <p class="text-xs text-gray-500 mt-1 max-w-md mx-auto">
                        {{ __('All :count rows were successfully validated, created, and assigned SLA due dates with zero failures.', ['count' => $import->successful_rows]) }}
                    </p>
                    <div class="mt-4">
                        <a href="{{ route('tickets.index') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                            {{ __('View Imported Tickets') }} &rarr;
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
