<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reference_images' => $this->input('reference_images', [])]);
    }

    public function rules(): array
    {
        return [
            'request_id' => [$this->routeIs('generations.store') ? 'required' : 'sometimes', 'uuid'],
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }
}
