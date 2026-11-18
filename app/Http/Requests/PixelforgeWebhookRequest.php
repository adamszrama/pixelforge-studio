<?php

namespace App\Http\Requests;

use App\Domain\Generations\GenerationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PixelforgeWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Validate only the signed body, never query parameters or normalized input. */
    public function validationData(): array
    {
        $payload = json_decode($this->getContent(), true);
        abort_unless(is_array($payload), 400, 'Invalid JSON payload.');

        return $payload;
    }

    public function rules(): array
    {
        $rules = [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
        ];

        if ($this->isGenerationEvent()) {
            $rules += [
                'data' => ['required', 'array'],
                'data.object' => ['required', 'array'],
                'data.object.id' => ['required', 'string', 'max:255'],
                'data.object.status' => ['required', Rule::enum(GenerationStatus::class)],
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->isGenerationEvent()) {
                return;
            }

            $payload = $this->validationData();
            if ($payload['type'] !== 'generation.'.$payload['data']['object']['status']) {
                $validator->errors()->add('type', 'The event type must match the generation status.');
            }
        }];
    }

    /** Preserve provider fields needed for billing and deferred reconciliation. */
    public function event(): array
    {
        return $this->validationData();
    }

    private function isGenerationEvent(): bool
    {
        $type = $this->validationData()['type'] ?? null;

        return is_string($type) && str_starts_with($type, 'generation.');
    }
}
