<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uom_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'unit_name' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'is_purchase_default' => ['nullable', 'boolean'],
            'is_sale_default' => ['nullable', 'boolean'],
        ];
    }
}
