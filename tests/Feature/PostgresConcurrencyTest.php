<?php

namespace Tests\Feature;

use App\Domain\Billing\Money;
use App\Domain\Generations\ApplyProviderState;
use App\Domain\Generations\CreateGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** SQLite cannot prove row-lock correctness. CI also runs these on PostgreSQL. */
class PostgresConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
    }

    public function test_concurrent_settlements_charge_one_time(): void
    {
        $user = User::factory()->create(['balance' => Money::fromUsd('10')]);
        $pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
        $generation = app(CreateGeneration::class)->execute($user, [
            'request_id' => (string) Str::uuid(), 'prompt' => 'A tree', 'size' => '1024x1024',
            'quality' => 'low', 'reference_images' => [],
        ], $pricing);
        $object = [
            'id' => 'gen_concurrent', 'status' => 'succeeded',
            'metadata' => ['generation_id' => $generation->id],
            'usage' => ['input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
            'rates' => $pricing['rates'], 'completed_at' => now()->toIso8601String(),
            'image_url' => 'https://example.com/image.png',
        ];
        $this->concurrently(fn () => app(ApplyProviderState::class)->execute($object));
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('9.984425000000', Money::usd($user->fresh()->balance));
        $this->assertSame(0, $user->fresh()->reserved);
    }

    public function test_concurrent_requests_cannot_spend_the_same_available_credit(): void
    {
        $user = User::factory()->create(['balance' => Money::fromUsd('0.03')]);
        $pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
        $this->concurrently(function () use ($user, $pricing) {
            try {
                app(CreateGeneration::class)->execute($user, [
                    'request_id' => (string) Str::uuid(), 'prompt' => 'A tree', 'size' => '1024x1024',
                    'quality' => 'low', 'reference_images' => [],
                ], $pricing);
            } catch (ValidationException) {
                // One of the simultaneous requests must fail affordability validation.
            }
        });
        $this->assertSame(1, Generation::count());
        $this->assertSame(Generation::first()->reserved_cost, $user->fresh()->reserved);
    }

    private function concurrently(\Closure $operation): void
    {
        DB::disconnect();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork a test worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    $operation();
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'Concurrent worker failed.');
        }
        DB::purge();
    }
}
