<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\SynchronizeGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $generationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->generationId))->dontRelease()->expireAfter(60)];
    }

    public function handle(SynchronizeGeneration $synchronize): void
    {
        $synchronize->execute(Generation::findOrFail($this->generationId));
    }
}
