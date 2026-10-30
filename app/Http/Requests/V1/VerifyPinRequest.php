<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits_between:4,8'],
            'staff_id' => ['nullable', 'integer', 'exists:staff_users,id'],
            'email' => ['nullable', 'email'],
            'workstation' => ['nullable', 'string', 'max:50'],
        ];
    }
}
