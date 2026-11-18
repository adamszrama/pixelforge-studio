<?php

namespace App\Services\Pixelforge;

use App\Domain\Generations\ApplyProviderState;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\RateLimiter;

final class SynchronizeGeneration
{
    public function __construct(
        private PixelforgeClient $client,
        private ApplyProviderState $applyProviderState,
    ) {}

    public function execute(Generation $generation): void
    {
        if ($generation->status->isTerminal() || $generation->needs_review || $generation->next_attempt_at?->isFuture()) {
            return;
        }
        // Shared database-backed limiter, leaving headroom for reports and other environments.
        if (! RateLimiter::attempt('pixelforge:requests', 40, fn () => true, 60)) {
            return;
        }
        $submitting = ! $generation->provider_id;
        $previouslyUncertain = $generation->acceptance_uncertain;
        if ($submitting && ! $this->prepareSubmission($generation)) {
            return;
        }
        $generation->update(['next_attempt_at' => now()->addMinute()]);
        try {
            $response = $submitting ? $this->client->submit($generation) : $this->client->retrieve($generation->provider_id);
        } catch (ConnectionException) {
            $this->retryLater($generation, $submitting, 'Connection interrupted. Checking safely before trying again.');

            return;
        }
        if ($response->successful()) {
            $this->applyProviderState->execute($response->json());

            return;
        }
        $this->handleFailure($generation, $response, $submitting, $previouslyUncertain);
    }

    private function prepareSubmission(Generation $generation): bool
    {
        if ($generation->submission_attempts >= config('pixelforge.max_submission_attempts')) {
            $generation->update([
                'needs_review' => true,
                'message' => 'Submission needs operator review. Credits remain reserved until acceptance is confirmed.',
            ]);

            return false;
        }

        // Persist before I/O: a killed worker must consume its retry budget too.
        $generation->increment('submission_attempts');
        $generation->update(['acceptance_uncertain' => true]);

        return true;
    }

    private function handleFailure(Generation $generation, Response $response, bool $submitting, bool $previouslyUncertain): void
    {
        $code = $response->json('error.code', 'provider_unavailable');
        $definitiveRejection = in_array($code, [
            'invalid_api_key', 'validation_failed', 'content_policy_violation',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded', 'overloaded',
        ], true);
        if ($submitting && ! $previouslyUncertain && $definitiveRejection) {
            $generation->update(['acceptance_uncertain' => false]);
        }
        $retryable = $response->status() === 429 || $response->serverError();
        $uncertain = $submitting && ($response->serverError() && $code !== 'overloaded');
        if ($submitting && ! $retryable && ! $generation->acceptance_uncertain) {
            $this->applyProviderState->reject($generation, $code, $this->failureMessage($code));

            return;
        }
        if ($submitting && $code === 'daily_limit_exceeded' && ! $generation->acceptance_uncertain) {
            $this->applyProviderState->reject($generation, $code, 'The provider daily quota is exhausted. No charge; try again tomorrow (UTC).');

            return;
        }
        if ($submitting && $generation->submission_attempts >= config('pixelforge.max_submission_attempts') && ! $uncertain && ! $generation->acceptance_uncertain) {
            $this->applyProviderState->reject($generation, $code, 'The provider is still busy after several attempts. No charge; please try again later.');

            return;
        }
        $this->retryLater($generation, $uncertain, 'The provider is busy or unavailable. We will check again automatically.', max(1, (int) $response->header('Retry-After')));
    }

    private function retryLater(Generation $generation, bool $uncertain, string $message, int $retryAfter = 0): void
    {
        // Conditional update prevents an overlapping webhook from being overwritten.
        $delay = max($retryAfter, min(900, 5 * (2 ** min($generation->submission_attempts, 7))) + random_int(0, 5));
        $changes = ['message' => $message, 'next_attempt_at' => now()->addSeconds($delay)];
        if ($uncertain) {
            $changes['acceptance_uncertain'] = true;
        }
        if (! $generation->provider_id && $generation->submission_attempts >= config('pixelforge.max_submission_attempts')) {
            $changes['needs_review'] = true;
            $changes['message'] = 'Acceptance could not be confirmed. An operator must reconcile this request; credits are reserved, not charged.';
        }
        Generation::whereKey($generation->id)->whereIn('status', ['queued', 'processing'])->update($changes);
    }

    private function failureMessage(string $code): string
    {
        return match ($code) {
            'content_policy_violation' => 'The provider rejected this prompt. Please revise it. You were not charged.',
            'invalid_api_key' => 'The provider connection needs operator attention. You were not charged.',
            default => 'The provider rejected the request ('.$code.'). You were not charged.',
        };
    }
}
