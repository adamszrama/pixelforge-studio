<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'purchase_id' => ['required', 'uuid'],
        ];
    }
}
