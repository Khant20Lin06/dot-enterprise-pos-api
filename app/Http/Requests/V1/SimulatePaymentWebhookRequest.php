<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class SimulatePaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:kbzpay,kpay,wavepay,wave'],
            'gateway_ref' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:successful,success,failed'],
            'amount' => ['nullable', 'numeric'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
