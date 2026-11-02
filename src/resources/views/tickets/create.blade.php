<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Submit a Support Ticket') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('Describe your issue in detail. Our support team will respond according to the priority SLA.') }}
                </p>
            </div>
            <a href="{{ route('tickets.index') }}" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900">
                &larr; {{ __('Back to Tickets') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <form action="{{ route('tickets.store') }}" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-6">
                    @csrf

                    <!-- Category Selection -->
                    <div>
                        <x-input-label for="category_id" :value="__('Ticket Category')" />
                        <select id="category_id" name="category_id" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                            <option value="">{{ __('Select a category...') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error class="mt-2" :messages="$errors->get('category_id')" />
                    </div>

                    <!-- Priority Selection -->
                    <div>
                        <x-input-label for="priority" :value="__('Priority Level')" />
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            @foreach ($priorities as $priorityCase)
                                @php
                                    $hours = $priorityCase->slaHours();
                                    $colorBadge = match($priorityCase->color()) {
                                        'red' => 'border-red-400 peer-checked:border-red-600 peer-checked:bg-red-50 text-red-700',
                                        'amber' => 'border-amber-400 peer-checked:border-amber-600 peer-checked:bg-amber-50 text-amber-700',
                                        'blue' => 'border-blue-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 text-blue-700',
                                        default => 'border-gray-300 peer-checked:border-gray-500 peer-checked:bg-gray-50 text-gray-700',
                                    };
                                @endphp
                                <label class="relative flex flex-col p-4 border rounded-lg cursor-pointer hover:bg-gray-50 transition-all {{ $colorBadge }}">
                                    <input type="radio" name="priority" value="{{ $priorityCase->value }}"
                                           class="sr-only peer"
                                           @checked(old('priority', \App\Enums\TicketPriority::MEDIUM->value) === $priorityCase->value)
                                           required>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold capitalize">{{ $priorityCase->label() }}</span>
                                        <span class="text-xs px-2 py-0.5 rounded bg-white shadow-sm font-mono border">
                                            {{ $hours }}h SLA
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-500 mt-2">
                                        @if ($priorityCase->value === 'critical')
                                            {{ __('System down or blocker') }}
                                        @elseif ($priorityCase->value === 'high')
                                            {{ __('Core feature impacted') }}
                                        @elseif ($priorityCase->value === 'medium')
                                            {{ __('Normal operational query') }}
                                        @else
                                            {{ __('Low priority inquiry') }}
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error class="mt-2" :messages="$errors->get('priority')" />
                    </div>

                    <!-- Subject -->
                    <div>
                        <x-input-label for="subject" :value="__('Subject')" />
                        <x-text-input id="subject" name="subject" type="text" class="mt-1 block w-full" :value="old('subject')" required placeholder="Brief summary of the issue..." maxlength="255" />
                        <x-input-error class="mt-2" :messages="$errors->get('subject')" />
                    </div>

                    <!-- Description -->
                    <div>
                        <x-input-label for="description" :value="__('Description')" />
                        <textarea id="description" name="description" rows="6" required minlength="10"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                  placeholder="Provide step-by-step details, error messages, and context...">{{ old('description') }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('Minimum 10 characters required.') }}</p>
                        <x-input-error class="mt-2" :messages="$errors->get('description')" />
                    </div>

                    <!-- Attachments -->
                    <div>
                        <x-input-label for="attachments" :value="__('Attach Files (Optional)')" />
                        <div class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-indigo-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="attachments" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                        <span>{{ __('Upload files') }}</span>
                                        <input id="attachments" name="attachments[]" type="file" multiple class="sr-only">
                                    </label>
                                    <p class="ps-1">{{ __('or drag and drop') }}</p>
                                </div>
                                <p class="text-xs text-gray-500">
                                    {{ __('Supported: JPG, PNG, PDF, DOC, DOCX, TXT, ZIP up to 10MB each') }}
                                </p>
                            </div>
                        </div>
                        <x-input-error class="mt-2" :messages="$errors->get('attachments')" />
                        <x-input-error class="mt-2" :messages="$errors->get('attachments.*')" />
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100">
                        <a href="{{ route('tickets.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Cancel') }}
                        </a>
                        <x-primary-button>
                            {{ __('Submit Ticket') }}
                        </x-primary-button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</x-app-layout>
