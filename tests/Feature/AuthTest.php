<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_never_grants_admin_from_input(): void
    {
        $this->post('/register', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery', 'is_admin' => true, 'balance' => 999999,
        ])->assertRedirect('/studio');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertFalse($user->is_admin);
        $this->assertSame(0, $user->balance);
    }

    public function test_guests_cannot_access_history_or_buy_credits(): void
    {
        $this->get('/studio')->assertRedirect('/login');
        $this->post('/credits')->assertRedirect('/login');
    }

    public function test_login_logout_and_invalid_credentials(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
