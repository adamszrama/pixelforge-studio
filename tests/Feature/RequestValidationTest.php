<?php

namespace Tests\Feature;

use App\Domain\Billing\Money;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    public function test_registration_and_login_validate_before_authentication(): void
    {
        $this->postJson('/register', [
            'name' => 'Ada',
            'email' => 'invalid-email',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/login', ['email' => 'invalid-email'])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_credit_purchase_validates_its_identifier_and_ignores_client_amount(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/credits', ['purchase_id' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_id');
        $this->assertDatabaseCount('ledger_entries', 0);

        $purchase = ['purchase_id' => (string) Str::uuid(), 'amount' => 999999];
        $this->post('/credits', $purchase)->assertRedirect();
        $this->post('/credits', $purchase)->assertRedirect();
        $this->assertSame(Money::fromUsd('10'), $user->fresh()->balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_invalid_report_ranges_never_contact_the_provider(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([
            'start=invalid&end=2026-11-18',
            'start=2026-11-18&end=2026-11-17',
            'start=2026-01-01&end=2026-04-03',
        ] as $query) {
            $this->getJson('/admin/reports?'.$query)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_report_defaults_are_validated_and_provider_outage_is_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 18));
        Http::fake(['*/usage*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/reports')->assertOk()->assertInertia(fn ($page) => $page
            ->where('start', '2026-10-20')
            ->where('end', '2026-11-18')
            ->has('days', 30)
            ->where('days.0.difference', null)
            ->where('totals.spend', '0.000000000000')
            ->where('error', 'Provider usage is unavailable. Local totals are shown; reconciliation is not verified.'));
    }
}
