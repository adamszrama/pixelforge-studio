<?php

namespace Tests\Feature;

use App\Domain\Billing\Money;
use App\Domain\Billing\Quote;
use App\Domain\Billing\Wallet;
use App\Domain\Generations\ApplyProviderState;
use App\Domain\Generations\CreateGeneration;
use App\Domain\Generations\GenerationStatus;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GenerationAccountingTest extends TestCase
{
    use RefreshDatabase;

    private array $pricing;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['pixelforge.webhook_secret' => 'whsec_test', 'pixelforge.api_key' => 'test-key']);
        $this->pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
        $this->user = User::factory()->create(['balance' => Money::fromUsd('10')]);
    }

    private function input(array $overrides = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'prompt' => 'A lighthouse',
            'size' => '1536x1024', 'quality' => 'high',
            'reference_images' => ['https://example.com/reference.png'],
        ], ...$overrides];
    }

    private function generation(array $overrides = []): Generation
    {
        return app(CreateGeneration::class)->execute($this->user, $this->input($overrides), $this->pricing);
    }

    private function object(Generation $generation, string $status = 'succeeded'): array
    {
        return [
            'id' => 'gen_'.$generation->id, 'object' => 'generation', 'status' => $status,
            'metadata' => ['generation_id' => $generation->id], 'rates' => $this->pricing['rates'],
            'usage' => $status === 'succeeded' ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411] : null,
            'image_url' => $status === 'succeeded' ? 'https://example.com/image.png' : null,
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-17T23:59:59Z' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image could be produced.'] : null,
        ];
    }

    private function webhook(array $object, string $id = 'evt_one', ?int $timestamp = null)
    {
        $payload = json_encode(['id' => $id, 'type' => 'generation.'.$object['status'], 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp ??= time();

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'),
        ], $payload);
    }

    public function test_exact_cost_uses_all_four_token_types_and_exact_markup(): void
    {
        $g = $this->generation();
        app(ApplyProviderState::class)->execute($this->object($g));
        $g->refresh();
        $this->assertSame('0.305510000000', Money::usd($g->provider_cost));
        $this->assertSame('0.381887500000', Money::usd($g->actual_cost));
        $this->assertSame('9.618112500000', Money::usd($this->user->fresh()->balance));
        $this->assertSame(0, $this->user->fresh()->reserved);
        $this->assertSame('2026-11-17', $g->completed_at->toDateString());
    }

    public function test_fractional_nanodollar_markup_is_preserved(): void
    {
        $this->assertSame('0.000000001250', Money::usd(Money::markedUp(Money::fromUsd('0.000000001'))));
    }

    public function test_quote_uses_unicode_characters_and_published_upper_bounds(): void
    {
        $input = $this->input(['prompt' => str_repeat('🌳', 400), 'reference_images' => ['https://example.com/1.png', 'https://example.com/2.png']]);
        $quote = app(Quote::class)->calculate($input, $this->pricing);
        $this->assertSame('0.467343750000', Money::usd($quote['maximum']));
        $this->assertLessThan($quote['maximum'], $quote['estimate']);
    }

    public function test_generation_snapshot_rates_win_over_current_prices(): void
    {
        $g = $this->generation();
        $object = $this->object($g);
        $object['rates']['reasoning_tokens'] = '25.00';
        app(ApplyProviderState::class)->execute($object);
        $this->assertSame('0.345310000000', Money::usd($g->fresh()->provider_cost));
    }

    public function test_duplicate_events_and_different_terminal_event_ids_charge_once(): void
    {
        $g = $this->generation();
        $object = $this->object($g);
        $this->webhook($object)->assertNoContent();
        $this->webhook($object)->assertNoContent();
        $this->webhook($object, 'evt_second')->assertNoContent();
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('9.618112500000', Money::usd($this->user->fresh()->balance));
    }

    public function test_early_terminal_webhook_cannot_be_regressed_by_submission_reply_or_processing(): void
    {
        $g = $this->generation();
        $this->assertNull($g->provider_id);
        $this->webhook($this->object($g))->assertNoContent();
        app(ApplyProviderState::class)->execute($this->object($g, 'queued'));
        $this->webhook($this->object($g, 'processing'), 'evt_late')->assertNoContent();
        $this->assertSame(GenerationStatus::Succeeded, $g->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_processing_cannot_regress_to_queued(): void
    {
        $g = $this->generation();
        app(ApplyProviderState::class)->execute($this->object($g, 'processing'));
        app(ApplyProviderState::class)->execute($this->object($g, 'queued'));
        $this->assertSame(GenerationStatus::Processing, $g->fresh()->status);
    }

    public function test_failure_is_free_and_releases_reservation_once(): void
    {
        $g = $this->generation();
        $this->webhook($this->object($g, 'failed'))->assertNoContent();
        $this->webhook($this->object($g, 'failed'))->assertNoContent();
        $this->webhook($this->object($g), 'evt_conflicting')->assertNoContent();
        $this->assertSame(GenerationStatus::Failed, $g->fresh()->status);
        $this->assertSame(0, $g->fresh()->actual_cost);
        $this->assertSame(Money::fromUsd('10'), $this->user->fresh()->balance);
        $this->assertSame(0, $this->user->fresh()->reserved);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_bad_old_and_future_signatures_have_no_side_effects(): void
    {
        $g = $this->generation();
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_bad'])->assertStatus(400);
        $this->webhook($this->object($g), 'evt_old', time() - 301)->assertStatus(400);
        $this->webhook($this->object($g), 'evt_future', time() + 301)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_unknown_event_is_durable_and_can_be_reconciled_later(): void
    {
        $id = (string) Str::uuid();
        $ghost = new Generation(['id' => $id]);
        $this->webhook($this->object($ghost))->assertNoContent();
        $this->assertNull(DB::table('webhook_events')->first()->processed_at);
        $g = $this->generation(['request_id' => $id]);
        $this->artisan('pixelforge:sync')->assertSuccessful();
        $this->assertSame(GenerationStatus::Succeeded, $g->fresh()->status);
        $this->assertNotNull(DB::table('webhook_events')->first()->processed_at);
    }

    public function test_invalid_billing_rolls_back_event_and_money_together(): void
    {
        $g = $this->generation();
        $object = $this->object($g);
        unset($object['usage']['reasoning_tokens']);
        $this->webhook($object)->assertStatus(500);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(GenerationStatus::Queued, $g->fresh()->status);
    }

    public function test_duplicate_submission_reserves_only_once_and_rejects_changed_body(): void
    {
        $input = $this->input();
        $first = app(CreateGeneration::class)->execute($this->user, $input, $this->pricing);
        $again = app(CreateGeneration::class)->execute($this->user, $input, $this->pricing);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->reserved_cost, $this->user->fresh()->reserved);
        Http::fake(['*/pricing' => Http::response($this->pricing)]);
        $this->actingAs($this->user)->post('/generations', [...$input, 'prompt' => 'Different'])->assertStatus(409);
    }

    public function test_reservations_prevent_overspending_before_completion(): void
    {
        $this->user->forceFill(['balance' => Money::fromUsd('0.5')])->save();
        $this->generation();
        $this->expectException(ValidationException::class);
        $this->generation();
    }

    public function test_fake_purchase_is_idempotent_and_cannot_change_amount(): void
    {
        $id = (string) Str::uuid();
        app(Wallet::class)->purchase($this->user, $id);
        app(Wallet::class)->purchase($this->user, $id);
        $this->assertSame(Money::fromUsd('20'), $this->user->fresh()->balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_rate_limit_respects_retry_after_and_reuses_idempotency_key(): void
    {
        $g = $this->generation();
        Http::fake(['*/generations' => Http::sequence()
            ->push(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '120'])
            ->push($this->object($g, 'queued'), 202)]);
        $this->runJob($g);
        $this->assertTrue($g->fresh()->next_attempt_at->gte(now()->addSeconds(119)));
        $this->assertNull($g->fresh()->actual_cost);
        $this->travel(125)->seconds();
        $this->runJob($g);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $g->id) && $request['metadata']['generation_id'] === $g->id);
        $this->assertNotNull($g->fresh()->provider_id);
    }

    public function test_timeout_then_replay_finds_original_generation_without_double_charge(): void
    {
        $g = $this->generation();
        Http::fake(['*/generations' => Http::failedConnection()]);
        $this->runJob($g);
        $this->assertTrue($g->fresh()->acceptance_uncertain);
        $this->assertSame(GenerationStatus::Queued, $g->fresh()->status);
        $this->travel(30)->seconds();
        Http::swap(new Factory);
        Http::fake(['*/generations' => Http::response($this->object($g), 202, ['Idempotent-Replayed' => 'true'])]);
        $this->runJob($g);
        $this->assertSame(GenerationStatus::Succeeded, $g->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_overload_is_bounded_and_rejected_requests_are_free(): void
    {
        $g = $this->generation();
        $g->update(['submission_attempts' => 7]);
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded']], 503)]);
        $this->runJob($g);
        $this->assertSame(GenerationStatus::Failed, $g->fresh()->status);
        $this->assertSame(0, $this->user->fresh()->reserved);
        $this->assertSame(0, $g->fresh()->actual_cost);
    }

    public function test_ambiguous_acceptance_is_never_marked_free_or_released_after_retry_exhaustion(): void
    {
        $g = $this->generation();
        $g->update(['submission_attempts' => 7, 'acceptance_uncertain' => true]);
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);
        $this->runJob($g);
        $this->assertTrue($g->fresh()->needs_review);
        $this->assertNull($g->fresh()->actual_cost);
        $this->assertSame($g->reserved_cost, $this->user->fresh()->reserved);
    }

    public function test_content_rejection_and_daily_quota_fail_without_charging(): void
    {
        foreach (['content_policy_violation' => 400, 'daily_limit_exceeded' => 429] as $code => $status) {
            $g = $this->generation();
            Http::fake(['*/generations' => Http::response(['error' => ['code' => $code]], $status)]);
            $this->runJob($g);
            $this->assertSame(GenerationStatus::Failed, $g->fresh()->status);
            $this->assertSame(0, $g->fresh()->actual_cost);
        }
        $this->assertSame(0, $this->user->fresh()->reserved);
    }

    public function test_polling_recovers_a_missing_webhook(): void
    {
        $g = $this->generation();
        $g->update(['provider_id' => 'gen_'.$g->id]);
        Http::fake(['*/generations/*' => Http::response($this->object($g))]);
        $this->runJob($g);
        $this->assertSame(GenerationStatus::Succeeded, $g->fresh()->status);
    }

    public function test_scheduler_recovers_a_lost_dispatch(): void
    {
        Queue::fake();
        $g = $this->generation();
        $this->artisan('pixelforge:sync')->assertSuccessful();
        Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $g->id);
    }

    public function test_history_is_private_and_reports_require_operator_role(): void
    {
        $g = $this->generation();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/studio')->assertOk()->assertInertia(fn ($page) => $page->has('generations.data', 0));
        $this->get('/admin/reports')->assertForbidden();
        $this->actingAs($this->user)->get('/studio')->assertOk()->assertInertia(fn ($page) => $page->has('generations.data', 1)->where('generations.data.0.id', $g->id));
    }

    public function test_report_reconciles_by_provider_completion_day_and_user(): void
    {
        $g = $this->generation();
        app(ApplyProviderState::class)->execute($this->object($g));
        $this->user->forceFill(['is_admin' => true])->save();
        Http::fake(['*/usage*' => Http::response(['data' => [['date' => '2026-11-17', 'cost_usd' => '0.305510000']]])]);
        $this->actingAs($this->user)->get('/admin/reports?start=2026-11-17&end=2026-11-18')->assertOk()->assertInertia(fn ($page) => $page
            ->where('days.0.difference', '0.000000000000')->where('days.0.count', 1)
            ->where('days.1.count', 0)->where('users.0.days.0.date', '2026-11-17')
            ->where('totals.profit', '0.076377500000'));
    }

    private function runJob(Generation $g): void
    {
        (new SyncGeneration($g->id))->handle(app(PixelforgeClient::class), app(ApplyProviderState::class));
    }
}
