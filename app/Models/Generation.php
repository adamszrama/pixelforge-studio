<?php

namespace App\Models;

use App\Domain\Generations\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array', 'pricing_snapshot' => 'array',
            'usage' => 'array', 'rates' => 'array',
            'estimated_cost' => 'integer', 'reserved_cost' => 'integer',
            'provider_cost' => 'integer', 'actual_cost' => 'integer',
            'needs_review' => 'boolean', 'acceptance_uncertain' => 'boolean',
            'completed_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
