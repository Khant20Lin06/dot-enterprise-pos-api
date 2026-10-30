<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateMechanicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'unique:mechanics,phone'],
            'specialization' => ['nullable', 'string', 'max:100'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:Active,Inactive,On Leave'],
            'avatar' => ['nullable', 'string', 'max:255'],
        ];
    }
}
