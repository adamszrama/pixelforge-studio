<?php

namespace App\Services\Pixelforge;

use App\Domain\Generations\ApplyProviderState;
use Illuminate\Support\Facades\DB;

final class ReceiveWebhook
{
    public function __construct(private ApplyProviderState $applyProviderState) {}

    /** @param array{id: string, type: string, data?: array} $event */
    public function execute(array $event): void
    {
        DB::transaction(function () use ($event): void {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'provider_id' => $event['data']['object']['id'] ?? null,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            if ($inserted) {
                $this->apply($event);
            }
        }, 3);
    }

    public function reconcilePending(): void
    {
        $events = DB::table('webhook_events')
            ->whereNull('processed_at')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        foreach ($events as $event) {
            DB::transaction(function () use ($event): void {
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $this->apply($payload);
            }, 3);
        }
    }

    /** @param array{id: string, type: string, data?: array} $event */
    private function apply(array $event): void
    {
        $isGeneration = str_starts_with($event['type'], 'generation.');
        if ($isGeneration && ! $this->applyProviderState->execute($event['data']['object'])) {
            return;
        }

        DB::table('webhook_events')->where('id', $event['id'])->update(['processed_at' => now()]);
    }
}
