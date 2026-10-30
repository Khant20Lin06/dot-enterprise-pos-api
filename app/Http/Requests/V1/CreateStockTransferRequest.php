<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:source_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_option_id' => ['nullable', 'integer', 'exists:product_options,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
