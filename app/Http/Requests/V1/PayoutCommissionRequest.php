<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class PayoutCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commission_ids' => ['nullable', 'array'],
            'commission_ids.*' => ['integer', 'exists:mechanic_commissions,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
