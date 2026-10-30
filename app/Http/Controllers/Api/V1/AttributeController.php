<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\CreateAttributeAction;
use App\Domains\Inventory\Models\Attribute;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateAttributeRequest;
use App\Http\Requests\V1\UpdateAttributeRequest;
use App\Http\Resources\V1\AttributeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttributeController extends Controller
{
    /**
     * List all product variant attributes (Size, Color, etc.) and their values.
     */
    public function index(): AnonymousResourceCollection
    {
        $attributes = Attribute::with('values')->orderBy('id')->get();

        return AttributeResource::collection($attributes);
    }

    /**
     * Create a new attribute with values.
     */
    public function store(CreateAttributeRequest $request, CreateAttributeAction $action): JsonResponse
    {
        $attribute = $action->execute($request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Attribute '{$attribute->name}' created successfully.",
            'data' => new AttributeResource($attribute),
        ], 201);
    }

    /**
     * Show details of a specific attribute and its values.
     */
    public function show(int $id): JsonResponse
    {
        $attribute = Attribute::with('values')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new AttributeResource($attribute),
        ]);
    }

    /**
     * Update attribute definition (name, code, or description).
     */
    public function update(UpdateAttributeRequest $request, int $id): JsonResponse
    {
        $attribute = Attribute::findOrFail($id);
        $attribute->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Attribute '{$attribute->name}' updated successfully.",
            'data' => new AttributeResource($attribute->load('values')),
        ]);
    }

    /**
     * Delete an attribute with relational guard against active variant references.
     */
    public function destroy(int $id): JsonResponse
    {
        $attribute = Attribute::with('values')->findOrFail($id);

        // Relational Guard: Cannot delete attribute if any of its values are assigned to product variants
        $inUse = $attribute->values()->whereHas('variants')->exists();
        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete attribute '{$attribute->name}' because one or more values are actively used by product variants. Remove or update referencing variants first.",
            ], 422);
        }

        $attrName = $attribute->name;
        // Clean up unreferenced child values
        $attribute->values()->delete();
        $attribute->delete();

        return response()->json([
            'success' => true,
            'message' => "Attribute '{$attrName}' and its values deleted successfully.",
        ]);
    }
}
