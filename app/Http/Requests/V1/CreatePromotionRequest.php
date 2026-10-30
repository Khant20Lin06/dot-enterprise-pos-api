<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:promotions,code'],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'target_tier' => ['nullable', 'string', 'in:All,Standard,Silver,VIP,Fleet'],
            'description' => ['nullable', 'string'],
        ];
    }
}
