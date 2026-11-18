<?php

namespace App\Http\Controllers;

use App\Domain\Generations\ApplyProviderState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PixelforgeWebhookController
{
    public function __invoke(Request $request, ApplyProviderState $apply): Response
    {
        $secret = config('pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 131072, 413);
        $header = (string) $request->header('Pixelforge-Signature');
        abort_unless(preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/D', $header, $parts), 400, 'Invalid signature.');
        abort_unless(abs(time() - (int) $parts[1]) <= 300 && hash_equals(hash_hmac('sha256', $parts[1].'.'.$raw, $secret), $parts[2]), 400, 'Invalid signature.');
        $event = json_decode($raw, true);
        abort_unless(is_array($event), 400);
        Validator::make($event, ['id' => 'required|string|max:255', 'type' => 'required|string|max:100'])->validate();
        if (str_starts_with($event['type'], 'generation.')) {
            Validator::make($event, [
                'data.object.id' => 'required|string|max:255',
                'data.object.status' => 'required|in:queued,processing,succeeded,failed',
            ])->validate();
            abort_unless($event['type'] === 'generation.'.$event['data']['object']['status'], 422);
        }
        DB::transaction(function () use ($event, $apply) {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'], 'type' => $event['type'],
                'provider_id' => $event['data']['object']['id'] ?? null,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            if (! $inserted) {
                return;
            }
            $processed = ! str_starts_with($event['type'], 'generation.') || $apply->execute($event['data']['object']);
            if ($processed) {
                DB::table('webhook_events')->where('id', $event['id'])->update(['processed_at' => now()]);
            }
        }, 3);

        return response()->noContent();
    }
}
