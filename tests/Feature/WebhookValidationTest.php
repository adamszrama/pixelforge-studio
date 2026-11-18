<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WebhookValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => 'whsec_test']);
    }

    public function test_ping_is_recorded_once_with_its_original_payload(): void
    {
        $payload = '{"id":"evt_ping","type":"ping","note":"  preserve spaces  "}';
        $this->sendSigned($payload)->assertNoContent();
        $this->sendSigned($payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $event = DB::table('webhook_events')->first();
        $this->assertNotNull($event->processed_at);
        $this->assertSame(json_decode($payload, true), json_decode($event->payload, true));
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_signed_payloads_have_no_side_effects(string $payload, int $status): void
    {
        $this->sendSigned($payload)->assertStatus($status);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'malformed JSON' => ['{"id":', 400],
            'scalar JSON' => ['true', 400],
            'missing envelope' => ['{}', 422],
            'non-string type' => ['{"id":"evt_one","type":[]}', 422],
            'missing generation' => ['{"id":"evt_one","type":"generation.processing"}', 422],
            'unknown status' => ['{"id":"evt_one","type":"generation.finished","data":{"object":{"id":"gen_one","status":"finished"}}}', 422],
            'conflicting status' => ['{"id":"evt_one","type":"generation.succeeded","data":{"object":{"id":"gen_one","status":"processing"}}}', 422],
        ];
    }

    public function test_query_parameters_cannot_supply_fields_missing_from_the_signed_body(): void
    {
        $this->sendSigned('{}', '/webhooks/pixelforge?id=evt_query&type=ping')
            ->assertUnprocessable()->assertJsonValidationErrors(['id', 'type']);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_invalid_signature_is_rejected_before_payload_validation(): void
    {
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.now()->timestamp.',v1='.str_repeat('0', 64),
        ], '{')->assertStatus(400)->assertJsonPath('message', 'Invalid signature.');
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_missing_configuration_and_oversized_payloads_are_rejected(): void
    {
        $this->sendSigned(str_repeat(' ', 131073))->assertStatus(413);
        config(['pixelforge.webhook_secret' => null]);
        $this->sendSigned('{"id":"evt_ping","type":"ping"}')->assertStatus(503);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    private function sendSigned(string $payload, string $url = '/webhooks/pixelforge'): TestResponse
    {
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }
}
