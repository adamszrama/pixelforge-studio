<?php

namespace App\Console\Commands;

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\ReceiveWebhook;
use Illuminate\Console\Command;

final class SyncGenerations extends Command
{
    protected $signature = 'pixelforge:sync';

    protected $description = 'Recover pending submissions, missed webhooks and polling updates';

    public function handle(ReceiveWebhook $webhooks): int
    {
        $webhooks->reconcilePending();

        Generation::query()
            ->whereIn('status', ['queued', 'processing'])
            ->where('needs_review', false)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(40)
            ->get()
            ->each(fn (Generation $generation) => SyncGeneration::dispatch($generation->id));

        return self::SUCCESS;
    }
}
