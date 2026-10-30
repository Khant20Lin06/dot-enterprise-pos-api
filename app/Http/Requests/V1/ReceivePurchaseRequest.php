<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'items' => 'nullable|array',
            'items.*.purchase_item_id' => 'required_with:items|integer|exists:purchase_items,id',
            'items.*.received_qty' => 'required_with:items|integer|min:1',
        ];
    }
}
