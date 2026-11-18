<?php

namespace App\Console\Commands;

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

final class ResumeGeneration extends Command
{
    protected $signature = 'pixelforge:resume {id}';

    protected $description = 'Resume a request after investigating provider connectivity';

    public function handle(): int
    {
        $generation = Generation::findOrFail($this->argument('id'));
        if ($generation->status->isTerminal()) {
            $this->error('A terminal generation cannot be resumed.');

            return self::FAILURE;
        }

        // Reuse the original permanent idempotency key and immutable request body.
        $generation->update([
            'needs_review' => false,
            'submission_attempts' => 0,
            'next_attempt_at' => now(),
        ]);
        SyncGeneration::dispatch($generation->id);
        $this->info('Safe reconciliation queued with the original idempotency key.');

        return self::SUCCESS;
    }
}
