<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseReturnStatusRequest extends FormRequest
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
            'status' => 'required|string|in:Dispatched,Credited,Replaced,Cancelled',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
