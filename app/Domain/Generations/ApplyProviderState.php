<?php

namespace App\Domain\Generations;

use App\Domain\Billing\Money;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Webhooks, submission replies and polling all pass through this transaction. */
final class ApplyProviderState
{
    public function execute(array $object): ?Generation
    {
        $id = $object['metadata']['generation_id'] ?? null;
        $candidate = Generation::where('provider_id', $object['id'])->first();
        $candidate ??= $id ? Generation::find($id) : null;
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $object) {
            // All money-changing paths lock the wallet first, then its generation.
            $user = User::query()->lockForUpdate()->findOrFail($candidate->user_id);
            $generation = Generation::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($generation->provider_id && $generation->provider_id !== $object['id']) {
                throw new \UnexpectedValueException('Provider generation identity mismatch.');
            }
            $generation->provider_id = $object['id'];
            if ($generation->status->isTerminal()) {
                $generation->save();

                return $generation;
            }
            $status = GenerationStatus::from($object['status']);
            if ($status === GenerationStatus::Queued && $generation->status === GenerationStatus::Processing) {
                return $generation;
            }
            $generation->status = $status;
            $generation->rates = $object['rates'] ?? null;
            $generation->message = null;
            $generation->needs_review = false;
            $generation->next_attempt_at = now()->addMinute();
            if ($status->isTerminal()) {
                $cost = $status === GenerationStatus::Succeeded ? Money::tokenCost($object['usage'], $object['rates']) : 0;
                $charge = Money::markedUp($cost);
                $completedAt = CarbonImmutable::parse($object['completed_at'])->utc();
                DB::table('ledger_entries')->insert([
                    'user_id' => $user->id, 'generation_id' => $generation->id,
                    'kind' => 'generation', 'amount' => -$charge, 'created_at' => $completedAt,
                ]);
                $user->balance -= $charge;
                $user->reserved -= $generation->reserved_cost;
                $user->save();
                $generation->fill([
                    'provider_cost' => $cost, 'actual_cost' => $charge,
                    'usage' => $object['usage'] ?? null,
                    'completed_at' => $completedAt, 'next_attempt_at' => null,
                    'image_url' => $status === GenerationStatus::Succeeded ? $object['image_url'] : null,
                    'image_url_expires_at' => $object['image_url_expires_at'] ?? null,
                    'error_code' => $object['error']['code'] ?? null,
                    'message' => $object['error']['message'] ?? null,
                ]);
            }
            $generation->save();

            return $generation;
        }, 3);
    }

    public function reject(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if ($locked->status->isTerminal() || $locked->provider_id || $locked->acceptance_uncertain) {
                return;
            }
            $user->reserved -= $locked->reserved_cost;
            $user->save();
            $locked->update([
                'status' => GenerationStatus::Failed, 'provider_cost' => 0, 'actual_cost' => 0,
                'error_code' => $code, 'message' => $message,
                'completed_at' => now(), 'next_attempt_at' => null,
            ]);
        }, 3);
    }
}
