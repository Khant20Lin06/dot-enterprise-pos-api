<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateCategoryRequest;
use App\Http\Requests\V1\UpdateCategoryRequest;
use App\Http\Resources\V1\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * List all categories.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Category::withCount('products');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $categories = $query->orderBy('name')->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Create a new category.
     */
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    /**
     * Show single category details.
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::withCount('products')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category),
        ]);
    }

    /**
     * Update category.
     */
    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => new CategoryResource($category),
        ]);
    }

    /**
     * Delete category (guarded against active products).
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        if ($category->products()->withTrashed()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete category '{$category->name}'. There are active or archived products linked to this category.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}
