<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\PricingAndPromotions\Models\PriceListItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreatePriceListRequest;
use App\Http\Requests\V1\UpdatePriceListRequest;
use App\Http\Resources\V1\PriceListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PriceListController extends Controller
{
    /**
     * List all wholesale, fleet, and promotional price lists.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PriceList::withCount('items');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $priceLists = $query->orderBy('name')->paginate(20);

        return PriceListResource::collection($priceLists);
    }

    /**
     * Create a new pricing tier with volume break lines.
     */
    public function store(CreatePriceListRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $priceList = DB::transaction(function () use ($validated) {
            $priceList = PriceList::create(array_diff_key($validated, ['items' => true]));

            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    PriceListItem::create([
                        'price_list_id' => $priceList->id,
                        'product_id' => $item['product_id'],
                        'min_qty' => $item['min_qty'],
                        'pricing_method' => $item['pricing_method'],
                        'value' => $item['value'],
                    ]);
                }
            }

            return $priceList->load(['items.product'])->loadCount('items');
        });

        return response()->json([
            'success' => true,
            'message' => "Price list '{$priceList->name}' created successfully.",
            'data' => new PriceListResource($priceList),
        ], 201);
    }

    /**
     * Show single price list with its volume break items.
     */
    public function show(int $id): JsonResponse
    {
        $priceList = PriceList::with(['items.product'])->withCount('items')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PriceListResource($priceList),
        ]);
    }

    /**
     * Update price list details and synchronized items.
     */
    public function update(UpdatePriceListRequest $request, int $id): JsonResponse
    {
        $priceList = PriceList::findOrFail($id);
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($priceList, $validated) {
            $priceList->update(array_diff_key($validated, ['items' => true]));

            if (isset($validated['items'])) {
                $priceList->items()->delete();

                foreach ($validated['items'] as $item) {
                    PriceListItem::create([
                        'price_list_id' => $priceList->id,
                        'product_id' => $item['product_id'],
                        'min_qty' => $item['min_qty'],
                        'pricing_method' => $item['pricing_method'],
                        'value' => $item['value'],
                    ]);
                }
            }

            return $priceList->fresh(['items.product'])->loadCount('items');
        });

        return response()->json([
            'success' => true,
            'message' => "Price list '{$updated->name}' updated successfully.",
            'data' => new PriceListResource($updated),
        ]);
    }

    /**
     * Delete price list.
     */
    public function destroy(int $id): JsonResponse
    {
        $priceList = PriceList::findOrFail($id);

        if ($priceList->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system default price list.',
            ], 422);
        }

        $priceList->delete();

        return response()->json([
            'success' => true,
            'message' => "Price list '{$priceList->name}' deleted.",
        ]);
    }
}
