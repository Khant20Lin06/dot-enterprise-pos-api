<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class SettleCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'in:Cash,KPay,WavePay,Card,Bank Transfer,cash,kpay,card'],
            'cashier_id' => ['nullable', 'integer', 'exists:staff_users,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
