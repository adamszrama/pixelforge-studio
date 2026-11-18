<?php

namespace App\Domain\Generations;

use App\Domain\Billing\Quote;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateGeneration
{
    public function __construct(private Quote $quote) {}

    public function execute(User $user, array $input, array $pricing): Generation
    {
        return DB::transaction(function () use ($user, $input, $pricing) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = Generation::find($input['request_id']);
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 409);
                foreach (['prompt', 'size', 'quality', 'reference_images'] as $field) {
                    abort_unless($input[$field] === $existing->$field, 409, 'Request ID already used for different parameters.');
                }

                return $existing;
            }
            $quote = $this->quote->calculate($input, $pricing);
            if ($lockedUser->balance - $lockedUser->reserved < $quote['maximum']) {
                throw ValidationException::withMessages(['credits' => 'Add credits to cover the maximum estimated cost.']);
            }
            $generation = Generation::create([
                'id' => $input['request_id'], 'user_id' => $user->id,
                'prompt' => $input['prompt'], 'size' => $input['size'], 'quality' => $input['quality'],
                'reference_images' => $input['reference_images'], 'pricing_snapshot' => $pricing,
                'estimated_cost' => $quote['estimate'], 'reserved_cost' => $quote['maximum'],
                'next_attempt_at' => now(),
            ]);
            $lockedUser->increment('reserved', $quote['maximum']);

            // This row is also the durable outbox: the scheduler recovers lost dispatches.
            return $generation;
        }, 3);
    }
}
