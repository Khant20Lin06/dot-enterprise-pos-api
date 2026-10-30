<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePaymentQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'provider' => ['required', 'string', 'in:kbzpay,kpay,wavepay,wave'],
            'order_no' => ['nullable', 'string', 'max:50'],
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
