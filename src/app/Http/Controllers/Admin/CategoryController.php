<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CategoryController extends Controller
{
    /**
     * Display a listing of categories with ticket counts.
     */
    public function index(Request $request): View|JsonResponse
    {
        $categories = TicketCategory::query()
            ->withCount('tickets')
            ->orderBy('name')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'categories' => $categories,
            ]);
        }

        return view('admin.categories.index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:ticket_categories,name'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while (TicketCategory::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        /** @var TicketCategory $category */
        $category = TicketCategory::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'is_active' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Category created successfully.',
                'category' => $category,
            ], 201);
        }

        return redirect()->route('admin.categories.index')
            ->with('status', "Category '{$category->name}' created successfully.");
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, TicketCategory $category): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('ticket_categories', 'name')->ignore($category->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $category->name = $validated['name'];
        }

        if ($request->has('toggle_status')) {
            $category->is_active = ! $category->is_active;
        } elseif (isset($validated['is_active'])) {
            $category->is_active = (bool) $validated['is_active'];
        }

        $category->save();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Category updated successfully.',
                'category' => $category,
            ]);
        }

        $statusText = $category->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.categories.index')
            ->with('status', "Category '{$category->name}' has been {$statusText}.");
    }

    /**
     * Remove the specified category from storage, or deactivate if it has tickets.
     */
    public function destroy(Request $request, TicketCategory $category): RedirectResponse|JsonResponse
    {
        if ($category->tickets()->exists()) {
            $category->update(['is_active' => false]);

            $message = "Category '{$category->name}' has associated tickets and cannot be deleted. It has been deactivated instead.";

            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'category' => $category], 200);
            }

            return redirect()->route('admin.categories.index')->with('status', $message);
        }

        $name = $category->name;
        $category->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => "Category '{$name}' deleted successfully."], 200);
        }

        return redirect()->route('admin.categories.index')
            ->with('status', "Category '{$name}' deleted successfully.");
    }
}
