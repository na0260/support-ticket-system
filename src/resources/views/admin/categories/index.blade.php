<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Category Management') }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('Configure support ticket categories, monitor usage, and toggle active status.') }}
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

            @if ($errors->any())
                <div class="p-4 bg-red-50 border-l-4 border-red-500 rounded-md text-sm text-red-800">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Create Category Card -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg h-fit">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">{{ __('Add New Category') }}</h3>
                    <p class="text-xs text-gray-500 mb-4">{{ __('Enter a unique descriptive name. The URL slug will be generated automatically.') }}</p>

                    <form action="{{ route('admin.categories.store') }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" :value="__('Category Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required placeholder="e.g. Hardware Diagnostics" maxlength="100" />
                            <x-input-error class="mt-1" :messages="$errors->get('name')" />
                        </div>

                        <div class="pt-2">
                            <x-primary-button class="w-full justify-center">
                                {{ __('Create Category') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>

                <!-- Categories Table Card -->
                <div class="lg:col-span-2 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Existing Categories') }}</h3>
                        <span class="text-xs text-gray-500">{{ $categories->count() }} {{ __('total') }}</span>
                    </div>

                    @if ($categories->isEmpty())
                        <div class="p-8 text-center text-sm text-gray-500">
                            {{ __('No categories configured yet.') }}
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Name & Slug') }}</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Total Tickets') }}</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach ($categories as $cat)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-semibold text-gray-900">{{ $cat->name }}</div>
                                                <div class="text-xs font-mono text-gray-400">{{ $cat->slug }}</div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <a href="{{ route('tickets.index', ['category_id' => $cat->id]) }}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
                                                    {{ $cat->tickets_count }} {{ __('tickets') }}
                                                </a>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                @if ($cat->is_active)
                                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                                        {{ __('Active') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-500/20">
                                                        {{ __('Inactive') }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                                <!-- Toggle Status Button -->
                                                <form action="{{ route('admin.categories.update', $cat) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="toggle_status" value="1">
                                                    <button type="submit" class="text-xs px-2.5 py-1 rounded border {{ $cat->is_active ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' }}">
                                                        {{ $cat->is_active ? __('Deactivate') : __('Activate') }}
                                                    </button>
                                                </form>

                                                <!-- Delete Button -->
                                                <form action="{{ route('admin.categories.destroy', $cat) }}" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete or deactivate this category?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-xs px-2.5 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50">
                                                        {{ __('Delete') }}
                                                    </button>
                                                </form>
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
    </div>
</x-app-layout>
