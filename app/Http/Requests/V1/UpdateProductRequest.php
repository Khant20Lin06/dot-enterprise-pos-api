<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('id') ?? $this->route('product');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'uom_id' => ['sometimes', 'required', 'integer', 'exists:units_of_measure,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'barcode' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($productId)],
            'oem_part_no' => ['nullable', 'string', 'max:100'],
            'car_fitment' => ['nullable', 'string'],
            'bin_location' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sell_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'is_labor' => ['nullable', 'boolean'],
            'is_outsourced' => ['nullable', 'boolean'],
        ];
    }
}
