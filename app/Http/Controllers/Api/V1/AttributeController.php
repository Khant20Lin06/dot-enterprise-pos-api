<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\CreateAttributeAction;
use App\Domains\Inventory\Models\Attribute;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateAttributeRequest;
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
}
