<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Standard CRUD for ticket categories (administrators only).
 * Every change clears two caches:
 *   - ticket:active-categories (ticket form dropdown)
 *   - dashboard:admin:stats    (the "tickets by category" chart shows category names)
 */
class CategoryController extends Controller
{
    public function index()
    {
        // withCount('tickets') adds a "tickets_count" attribute using one sub-query.
        $categories = Category::withCount('tickets')->orderBy('name')->paginate(15);

        return view('categories.index', ['categories' => $categories]);
    }

    public function create()
    {
        return view('categories.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        Category::create($validated);
        $this->clearCaches();

        return redirect()->route('categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category)
    {
        return view('categories.edit', ['category' => $category]);
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $category->update($validated);
        $this->clearCaches();

        return redirect()->route('categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        // Business rule: categories used by tickets are kept (deactivate them instead).
        // The foreign key (restrictOnDelete) would also block this at database level.
        if ($category->tickets()->exists()) {
            return back()->with('error', 'This category is used by existing tickets and cannot be deleted. Deactivate it instead.');
        }

        $category->delete();
        $this->clearCaches();

        return redirect()->route('categories.index')->with('success', 'Category deleted.');
    }

    private function clearCaches(): void
    {
        Category::clearActiveCache();
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
    }
}
