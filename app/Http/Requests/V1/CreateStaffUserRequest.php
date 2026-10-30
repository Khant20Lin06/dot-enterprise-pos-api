<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:191', 'unique:staff_users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'password' => ['nullable', 'string', 'min:6'],
            'workstation' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:Active,Suspended,On Leave,active,suspended,on_leave'],
            'avatar' => ['nullable', 'string', 'max:255'],
        ];
    }
}
