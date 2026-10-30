<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $promoId = $this->route('id');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('promotions', 'code')->ignore($promoId)],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'in:percent,fixed'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'target_tier' => ['nullable', 'string', 'in:All,Standard,Silver,VIP,Fleet'],
            'description' => ['nullable', 'string'],
        ];
    }
}
