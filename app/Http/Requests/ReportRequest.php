<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewReports') ?? false;
    }

    public function rules(): array
    {
        return [
            'start' => ['sometimes', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'start' => now()->utc()->subDays(29)->toDateString(),
            'end' => now()->utc()->toDateString(),
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start'), 'UTC');
            $end = CarbonImmutable::parse($this->input('end'), 'UTC')->endOfDay();
            if ($start->diffInDays($end) >= 92) {
                $validator->errors()->add('end', 'Select at most 92 days.');
            }
        }];
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('start'), 'UTC')->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('end'), 'UTC')->endOfDay();
    }
}
