<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
            'password' => ['nullable', 'string', 'min:6'],
            'workstation' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:Active,Suspended,On Leave,active,suspended,on_leave'],
            'avatar' => ['nullable', 'string', 'max:255'],
        ];
    }
}
