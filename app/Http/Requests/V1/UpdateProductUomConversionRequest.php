<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('price') && ! $this->has('sell_price')) {
            $this->merge(['sell_price' => $this->input('price')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $conversionId = (int) $this->route('conversionId');

        return [
            'conversion_factor' => 'sometimes|required|numeric|gt:0',
            'sell_price' => 'sometimes|required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'unit_name' => 'nullable|string|max:100',
            'barcode' => "nullable|string|max:100|unique:product_uom_conversions,barcode,{$conversionId}",
            'sku' => 'nullable|string|max:100',
            'is_purchase_default' => 'nullable|boolean',
            'is_sale_default' => 'nullable|boolean',
        ];
    }
}
