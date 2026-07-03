<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\FAQCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FAQCategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:faq_categories|max:255',
        ]);

        FAQCategory::create([
            'name' => $request->name,
            'status' => 1,
            'click_count' => 0,
        ]);

        $result = $this->renderList();

        return response()->json([
            'message' => translate('Category added successfully.'),
            'html' => $result['html'],
            'count' => $result['count'],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:faq_categories,name,' . $id . '|max:255',
        ]);

        $category = FAQCategory::findOrFail($id);
        $category->update([
            'name' => $request->name,
        ]);

        $result = $this->renderList();

        return response()->json([
            'message' => translate('Category updated successfully.'),
            'html' => $result['html'],
            'count' => $result['count'],
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $category = FAQCategory::findOrFail($id);
        $category->update(['status' => $request->status]);

        $result = $this->renderList();

        return response()->json([
            'message' => translate('Status updated successfully.'),
            'html' => $result['html'],
            'count' => $result['count'],
        ]);
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        FAQCategory::findOrFail($id)->delete();

        $search = $request->input('search');
        $result = $this->renderList($search);

        return response()->json([
            'message' => translate('Category deleted successfully.'),
            'html' => $result['html'],
            'count' => $result['count'],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $search = $request->input('search');

        $result = $this->renderList($search);

        return response()->json([
            'html' => $result['html'],
            'count' => $result['count'],
        ]);
    }

    private function renderList(?string $search = null): array
    {
        $categoryPriority =  helpers::get_business_settings('faq_category_priority_type') ?? 'latest';

        // Define allowed priority types for validation
        $allowedPriorities = ['latest', 'popularity', 'a_to_z', 'z_to_a'];

        $categories = FAQCategory::query()
            ->when($search, function ($query, $search) {
                return $query->where('name', 'LIKE', "%{$search}%");
            })
            ->when($categoryPriority == 'latest', function ($query) {
                $query->latest();
            })
            ->when($categoryPriority == 'popularity', function ($query) {
                $query->orderBy('click_count', 'DESC');
            })
            ->when($categoryPriority == 'a_to_z', function ($query) {
                $query->orderBy('name', 'ASC');
            })
            ->when($categoryPriority == 'z_to_a', function ($query) {
                $query->orderBy('name', 'DESC');
            })
            ->when(!in_array($categoryPriority, $allowedPriorities), function ($query) {
                // Default to latest if priority is invalid
                $query->latest();
            })
            ->get();

        $html =  view('admin-views.faq.category.partials.table-rows', compact('categories'))->render();

        return [
            'html' => $html,
            'count' => $categories->count(),
        ];
    }

    public function countIncrement(int $id): JsonResponse
    {
        try {
            FAQCategory::findOrFail($id)->increment('click_count');
            return response()->json(['message' => 'Click count incremented successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Category not found'], 404);
        }
    }
}
