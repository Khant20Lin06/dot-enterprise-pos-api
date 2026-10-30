<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_items' => ['nullable', 'array'],
            'received_items.*.transfer_item_id' => ['required_with:received_items', 'integer'],
            'received_items.*.qty_received' => ['required_with:received_items', 'integer', 'min:0'],
        ];
    }
}
