<?php

namespace App\Http\Resources;

use App\Domain\Billing\Money;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
final class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'status' => $this->status->value,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'download_url' => route('generations.download', $this->id),
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'estimated_cost' => Money::usd($this->estimated_cost),
            'actual_cost' => $this->actual_cost === null ? null : Money::usd($this->actual_cost),
            'image_url' => $this->image_url,
            'image_expired' => $this->image_url_expires_at?->isPast() ?? false,
            'message' => $this->message,
            'needs_review' => $this->needs_review,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
