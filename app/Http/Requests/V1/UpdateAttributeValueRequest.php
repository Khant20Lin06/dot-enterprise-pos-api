<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => 'sometimes|required|string|max:100',
            'color_hex' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
