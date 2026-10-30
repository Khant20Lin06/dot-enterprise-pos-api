<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'uom_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:200'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'oem_part_no' => ['nullable', 'string', 'max:100'],
            'car_fitment' => ['nullable', 'string'],
            'bin_location' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'initial_stock' => ['nullable', 'integer', 'min:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'is_labor' => ['nullable', 'boolean'],
            'is_outsourced' => ['nullable', 'boolean'],
        ];
    }
}
