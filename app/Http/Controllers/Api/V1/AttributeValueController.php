<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\Attribute;
use App\Domains\Inventory\Models\AttributeValue;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateAttributeValueRequest;
use App\Http\Requests\V1\UpdateAttributeValueRequest;
use App\Http\Resources\V1\AttributeValueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttributeValueController extends Controller
{
    /**
     * List all values for a specific attribute.
     */
    public function index(int $attributeId): AnonymousResourceCollection
    {
        $attribute = Attribute::findOrFail($attributeId);
        $values = $attribute->values()->orderBy('sort_order')->orderBy('id')->get();

        return AttributeValueResource::collection($values);
    }

    /**
     * Add a single new value to an existing attribute.
     */
    public function store(int $attributeId, CreateAttributeValueRequest $request): JsonResponse
    {
        $attribute = Attribute::findOrFail($attributeId);

        $value = $attribute->values()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Value '{$value->value}' added to attribute '{$attribute->name}'.",
            'data' => new AttributeValueResource($value),
        ], 201);
    }

    /**
     * Show details of a specific attribute value.
     */
    public function show(int $attributeId, int $valueId): JsonResponse
    {
        $value = AttributeValue::where('attribute_id', $attributeId)->findOrFail($valueId);

        return response()->json([
            'success' => true,
            'data' => new AttributeValueResource($value),
        ]);
    }

    /**
     * Update an attribute value (typo correction, color code, or sort order).
     */
    public function update(int $attributeId, int $valueId, UpdateAttributeValueRequest $request): JsonResponse
    {
        $value = AttributeValue::where('attribute_id', $attributeId)->findOrFail($valueId);

        $value->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Attribute value updated successfully.",
            'data' => new AttributeValueResource($value),
        ]);
    }

    /**
     * Delete an attribute value with relational guard against active product variants.
     */
    public function destroy(int $attributeId, int $valueId): JsonResponse
    {
        $value = AttributeValue::where('attribute_id', $attributeId)->findOrFail($valueId);

        if ($value->variants()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete attribute value '{$value->value}' because it is actively assigned to product variants. Remove or update referencing variants first.",
            ], 422);
        }

        $valueName = $value->value;
        $value->delete();

        return response()->json([
            'success' => true,
            'message' => "Attribute value '{$valueName}' deleted successfully.",
        ]);
    }
}
