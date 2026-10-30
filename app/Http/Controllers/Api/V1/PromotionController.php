<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\PricingAndPromotions\Models\Promotion;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreatePromotionRequest;
use App\Http\Requests\V1\UpdatePromotionRequest;
use App\Http\Resources\V1\PromotionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PromotionController extends Controller
{
    /**
     * List all promotion campaigns and discount vouchers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Promotion::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('target_tier')) {
            $query->where('target_tier', $request->query('target_tier'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $promotions = $query->latest('id')->paginate(20);

        return PromotionResource::collection($promotions);
    }

    /**
     * Create a new promotional voucher or seasonal discount campaign.
     */
    public function store(CreatePromotionRequest $request): JsonResponse
    {
        $promotion = Promotion::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Promotion '{$promotion->title}' created successfully.",
            'data' => new PromotionResource($promotion),
        ], 201);
    }

    /**
     * Show single promotion campaign details.
     */
    public function show(int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PromotionResource($promotion),
        ]);
    }

    /**
     * Update promotion campaign rules.
     */
    public function update(UpdatePromotionRequest $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Promotion '{$promotion->title}' updated successfully.",
            'data' => new PromotionResource($promotion->fresh()),
        ]);
    }

    /**
     * Delete promotion campaign.
     */
    public function destroy(int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->delete();

        return response()->json([
            'success' => true,
            'message' => "Promotion '{$promotion->title}' deleted.",
        ]);
    }
}
