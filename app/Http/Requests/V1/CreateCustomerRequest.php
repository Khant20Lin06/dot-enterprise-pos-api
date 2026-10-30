<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:50', 'unique:customers,phone'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'tier' => ['nullable', 'string', 'in:Standard,Silver,VIP,Fleet'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
