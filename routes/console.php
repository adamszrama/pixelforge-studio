<?php

use App\Domain\Generations\ApplyProviderState;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pixelforge:sync', function () {
    DB::table('webhook_events')->whereNull('processed_at')->orderBy('created_at')->limit(100)->get()->each(function ($event) {
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        if (isset($payload['data']['object']) && app(ApplyProviderState::class)->execute($payload['data']['object'])) {
            DB::table('webhook_events')->where('id', $event->id)->update(['processed_at' => now()]);
        }
    });
    Generation::whereIn('status', ['queued', 'processing'])->where('needs_review', false)
        ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(40)
        ->get()->each(fn ($g) => SyncGeneration::dispatch($g->id));
})->purpose('Recover pending submissions, missed webhooks and polling updates');

Artisan::command('pixelforge:resume {id}', function (string $id) {
    $generation = Generation::findOrFail($id);
    if ($generation->status->isTerminal()) {
        $this->error('A terminal generation cannot be resumed.');

        return 1;
    }
    // Reuse the original permanent idempotency key and immutable request body.
    $generation->update(['needs_review' => false, 'submission_attempts' => 0, 'next_attempt_at' => now()]);
    SyncGeneration::dispatch($id);
    $this->info('Safe reconciliation queued with the original idempotency key.');
})->purpose('Resume a request after investigating provider connectivity');

Artisan::command('app:make-admin {email}', function (string $email) {
    User::where('email', $email)->firstOrFail()->forceFill(['is_admin' => true])->save();
    $this->info('Administrator access granted.');
});

Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
