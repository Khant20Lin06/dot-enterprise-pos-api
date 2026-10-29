<?php

namespace App\Domains\PricingAndPromotions\Actions;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\PricingAndPromotions\Models\PriceListItem;

class ResolveDynamicPriceAction
{
    /**
     * Resolve effective product unit price based on customer price list and volume break tiers.
     */
    public function execute(Product $product, int $qty = 1, ?int $customerId = null, ?int $overridePriceListId = null): array
    {
        $originalPrice = (float) $product->sell_price;
        $unitPrice = $originalPrice;
        $priceListName = 'Standard Retail';
        $isCustom = false;

        // 1. Resolve Target Price List
        $priceListId = $overridePriceListId;
        if (!$priceListId && $customerId) {
            $customer = Customer::find($customerId);
            $priceListId = $customer?->price_list_id;
        }

        if (!$priceListId) {
            $defaultPL = PriceList::where('is_default', true)->where('is_active', true)->first();
            $priceListId = $defaultPL?->id;
        }

        // 2. Evaluate Price List Item with Volume Break Tier
        if ($priceListId) {
            $priceList = PriceList::find($priceListId);
            if ($priceList && $priceList->is_active) {
                $priceListName = $priceList->name;

                // Look for volume break tier: min_qty <= $qty, ordered by highest min_qty desc
                $tierItem = PriceListItem::where('price_list_id', $priceListId)
                    ->where('product_id', $product->id)
                    ->where('min_qty', '<=', $qty)
                    ->orderBy('min_qty', 'desc')
                    ->first();

                if ($tierItem) {
                    $isCustom = true;
                    if ($tierItem->pricing_method === 'FIXED') {
                        $unitPrice = (float) $tierItem->value;
                    } elseif ($tierItem->pricing_method === 'PERCENT_DISCOUNT') {
                        $unitPrice = $originalPrice * (1 - ((float) $tierItem->value / 100));
                    } elseif ($tierItem->pricing_method === 'COST_PLUS_MARKUP') {
                        $unitPrice = (float) $product->cost_price * (1 + ((float) $tierItem->value / 100));
                    }
                } elseif ($priceList->type === 'WHOLESALE' && $product->wholesale_price) {
                    $unitPrice = (float) $product->wholesale_price;
                    $isCustom = true;
                }
            }
        }

        $savings = max(0.00, $originalPrice - $unitPrice);

        return [
            'unit_price' => round($unitPrice, 2),
            'original_price' => round($originalPrice, 2),
            'savings' => round($savings, 2),
            'price_list_name' => $priceListName,
            'is_custom' => $isCustom,
        ];
    }
}
