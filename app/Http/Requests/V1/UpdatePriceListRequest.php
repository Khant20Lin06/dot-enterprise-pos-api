<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $priceListId = $this->route('id');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('price_lists', 'code')->ignore($priceListId)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:RETAIL,WHOLESALE,FLEET,PROMOTIONAL'],
            'currency' => ['nullable', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.min_qty' => ['required', 'integer', 'min:1'],
            'items.*.pricing_method' => ['required', 'string', 'in:FIXED,PERCENT_DISCOUNT,COST_PLUS_MARKUP'],
            'items.*.value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
