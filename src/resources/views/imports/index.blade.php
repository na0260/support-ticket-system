<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Bulk Ticket Import') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('Import thousands of support tickets via CSV with background queue processing and failure tracking.') }}
                </p>
            </div>
            <div>
                <a href="{{ route('imports.sample') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-xs font-semibold rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    <svg class="w-4 h-4 me-1.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    {{ __('Download Sample CSV') }}
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

            @if (session('error'))
                <div class="p-4 bg-red-50 border-l-4 border-red-500 rounded-md text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border-l-4 border-red-500 rounded-md text-sm text-red-800">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- CSV Upload Form Card -->
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="text-base font-semibold text-gray-900 mb-2">{{ __('Upload New CSV File') }}</h3>
                <p class="text-xs text-gray-500 mb-4">
                    {{ __('Required columns:') }} <code class="bg-gray-100 px-1 py-0.5 rounded text-indigo-600 font-mono">subject, description, priority, category, customer_email</code>.
                    {{ __('Optional columns:') }} <code class="bg-gray-100 px-1 py-0.5 rounded text-gray-600 font-mono">status, assigned_agent_email, ticket_number</code>.
                    {{ __('Max file size: 20MB.') }}
                </p>

                <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="w-full sm:flex-1">
                            <label for="file" class="sr-only">{{ __('Choose CSV file') }}</label>
                            <input type="file"
                                   name="file"
                                   id="file"
                                   accept=".csv,text/csv,text/plain"
                                   required
                                   class="block w-full text-sm text-gray-500 file:me-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-gray-300 rounded-md shadow-sm cursor-pointer focus:outline-none focus:border-indigo-500">
                        </div>
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                            <svg class="w-4 h-4 me-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            {{ __('Upload & Start Import') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Import History Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Import History & Batches') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Real-time background processing status and failure reports.') }}</p>
                    </div>
                </div>

                @if ($imports->isEmpty())
                    <div class="p-12 text-center text-sm text-gray-500">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <h3 class="mt-2 text-sm font-semibold text-gray-900">{{ __('No imports yet') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Upload a CSV file above to start importing support tickets.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Batch / Date') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('File Name') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Initiated By') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Progress') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Success / Fail') }}</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($imports as $import)
                                    @php
                                        $statusBadge = match($import->status) {
                                            'completed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
                                            'processing' => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20 animate-pulse',
                                            'failed' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
                                            default => 'bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/20',
                                        };
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <div class="font-semibold text-indigo-600">
                                                <a href="{{ route('imports.show', $import) }}" class="hover:underline">#{{ $import->id }}</a>
                                            </div>
                                            <div class="text-xs text-gray-500" title="{{ $import->created_at->format('Y-m-d H:i:s') }}">
                                                {{ $import->created_at->diffForHumans() }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800">
                                            {{ $import->file_name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            {{ $import->user->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium uppercase {{ $statusBadge }}">
                                                {{ $import->status }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-600">
                                            <div class="w-32 bg-gray-200 rounded-full h-2 mb-1">
                                                <div class="bg-indigo-600 h-2 rounded-full transition-all" style="width: {{ $import->progressPercentage() }}%"></div>
                                            </div>
                                            <span>{{ $import->processed_rows }} / {{ $import->total_rows }} ({{ $import->progressPercentage() }}%)</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-emerald-700 bg-emerald-50 font-medium">
                                                {{ $import->successful_rows }} {{ __('ok') }}
                                            </span>
                                            @if ($import->failed_rows > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-red-700 bg-red-50 font-medium ml-1">
                                                    {{ $import->failed_rows }} {{ __('failed') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                            <a href="{{ route('imports.show', $import) }}" class="text-indigo-600 hover:text-indigo-900">
                                                {{ __('View Details') }} &rarr;
                                            </a>
                                            @if ($import->hasFailures())
                                                <a href="{{ route('imports.failures', $import) }}" class="text-red-600 hover:text-red-900 font-semibold">
                                                    {{ __('Download Errors') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($imports->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">
                            {{ $imports->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
