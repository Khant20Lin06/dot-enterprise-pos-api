<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:attributes,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'values' => ['required', 'array', 'min:1'],
            'values.*.value' => ['required', 'string', 'max:100'],
            'values.*.color_hex' => ['nullable', 'string', 'max:20'],
        ];
    }
}
