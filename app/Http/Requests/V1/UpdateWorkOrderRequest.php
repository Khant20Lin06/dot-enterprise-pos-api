<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_mechanic_id' => ['nullable', 'integer', 'exists:mechanics,id'],
            'bay' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'string', 'in:Normal,High,Urgent'],
            'complaint' => ['sometimes', 'required', 'string'],
            'mileage_in' => ['nullable', 'integer', 'min:0'],
            'fuel_level' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.item_type' => ['nullable', 'string', 'in:part,labor'],
            'items.*.name' => ['nullable', 'string', 'max:200'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.is_approved' => ['nullable', 'boolean'],
        ];
    }
}
