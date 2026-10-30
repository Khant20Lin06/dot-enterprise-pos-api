<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeRequest extends FormRequest
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
        $attributeId = (int) $this->route('id');

        return [
            'name' => 'sometimes|required|string|max:100',
            'code' => "sometimes|required|string|max:50|unique:attributes,code,{$attributeId}",
            'description' => 'nullable|string|max:500',
        ];
    }
}
