<?php

namespace Tests\Feature;

use App\Domain\Billing\Money;
use App\Domain\Generations\CreateGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenerationDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['balance' => Money::fromUsd('10')]);
        $pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
        $this->generation = app(CreateGeneration::class)->execute($this->user, [
            'request_id' => (string) Str::uuid(), 'prompt' => 'A tree', 'size' => '1024x1024',
            'quality' => 'low', 'reference_images' => [],
        ], $pricing);
        $this->generation->update([
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test.png?signature=test',
            'image_url_expires_at' => now()->addDay(),
        ]);
    }

    public function test_owner_can_download_png_without_forwarding_provider_credentials(): void
    {
        $png = "\x89PNG\r\n\x1a\nexample";
        Http::fake(['*/images/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $this->actingAs($this->user)->get($this->url())
            ->assertOk()
            ->assertDownload('pixelforge-'.$this->generation->id.'.png')
            ->assertHeader('Content-Type', 'image/png')
            ->assertStreamedContent($png);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_guests_and_other_users_cannot_download_an_image(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->getJson($this->url())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_expired_and_unfinished_images_are_rejected_before_fetching(): void
    {
        $this->actingAs($this->user);
        $this->generation->update(['image_url_expires_at' => now()->subMinute()]);
        $this->getJson($this->url())->assertGone();
        $this->generation->update(['status' => 'processing']);
        $this->getJson($this->url())->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_untrusted_image_addresses_cannot_be_used_as_a_proxy(): void
    {
        $this->actingAs($this->user);
        foreach ([
            'http://169.254.169.254/latest/meta-data',
            'https://attacker.example/images/test.png',
            'https://pixelforge.promptedit.dev/v1/usage',
            'https://user@pixelforge.promptedit.dev/images/test.png',
            'https://pixelforge.promptedit.dev:8443/images/test.png',
        ] as $url) {
            $this->generation->update(['image_url' => $url]);
            $this->getJson($this->url())->assertStatus(502);
        }
        Http::assertNothingSent();
    }

    public function test_redirects_are_not_followed_and_non_images_are_rejected(): void
    {
        $this->actingAs($this->user);
        Http::fake(['*/images/*' => Http::sequence()
            ->push('', 302, ['Location' => 'http://169.254.169.254/'])
            ->push('<html>Unexpected response</html>', 200)]);
        $this->getJson($this->url())->assertStatus(502);
        $this->getJson($this->url())->assertStatus(502);
        Http::assertSentCount(2);
    }

    public function test_provider_expiration_and_outage_have_readable_errors(): void
    {
        $this->actingAs($this->user);
        Http::fake(['*/images/*' => Http::sequence()->push('', 403)->push('', 503)]);
        $this->getJson($this->url())->assertGone()->assertJsonPath('message', 'This image link is no longer available.');
        $this->getJson($this->url())->assertStatus(502)->assertJsonPath('message', 'The image provider is unavailable. Please try again.');
    }

    public function test_connection_failures_do_not_leak_provider_details(): void
    {
        Http::fake(['*/images/*' => Http::failedConnection()]);
        $this->actingAs($this->user)->getJson($this->url())->assertStatus(502)
            ->assertJsonPath('message', 'The image provider is unavailable. Please try again.');
    }

    private function url(): string
    {
        return route('generations.download', $this->generation->id);
    }
}
