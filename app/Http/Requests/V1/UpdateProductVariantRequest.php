<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variantId = $this->route('variantId') ?? $this->route('variant');

        return [
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')->ignore($variantId),
            ],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_variants', 'barcode')->ignore($variantId),
            ],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
