<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_profile(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'test@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
            'registration_provider' => 'email',
            'last_login_provider' => 'email',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'event_type' => 'register',
            'provider' => 'email',
        ]);
    }

    public function test_login_returns_user_for_valid_credentials(): void
    {
        User::factory()->create([
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.name', 'Login User');

        $this->assertDatabaseHas('users', [
            'email' => 'login@example.com',
            'last_login_provider' => 'email',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'event_type' => 'login',
            'provider' => 'email',
        ]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'wrong@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'wrong@example.com',
            'password' => 'bad-password',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_google_auth_creates_user_with_verified_token(): void
    {
        config(['services.google.client_id' => 'google-client-id']);

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'google-client-id',
                'email' => 'google-user@example.com',
                'name' => 'Google User',
                'sub' => 'google-sub-1',
                'email_verified' => 'true',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'fake-id-token',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'google-user@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'google-user@example.com',
            'name' => 'Google User',
            'registration_provider' => 'google',
            'google_id' => 'google-sub-1',
            'last_login_provider' => 'google',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'event_type' => 'login',
            'provider' => 'google',
        ]);
    }

    public function test_google_auth_rejects_unverified_token(): void
    {
        config(['services.google.client_id' => 'google-client-id']);

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'other-client-id',
                'email' => 'google-user@example.com',
                'name' => 'Google User',
                'sub' => 'google-sub-2',
                'email_verified' => 'false',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'fake-id-token',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Unable to verify Google account.');
    }

    public function test_google_auth_accepts_code_flow(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'id_token' => 'id-token-from-google',
            ], 200),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'google-client-id',
                'email' => 'code-user@example.com',
                'name' => 'Code User',
                'sub' => 'google-sub-3',
                'email_verified' => 'true',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'code' => 'google-auth-code',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'code-user@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'code-user@example.com',
            'google_id' => 'google-sub-3',
        ]);
    }

    public function test_forgot_password_creates_reset_token_and_records_event(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);

        $user = User::factory()->create([
            'email' => 'forgot@example.com',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'forgot@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'forgot@example.com',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'user_id' => $user->id,
            'event_type' => 'forgot_password',
            'provider' => 'email',
        ]);
    }

    public function test_reset_password_updates_user_password_and_records_event(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'old-password',
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'reset@example.com',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'user_id' => $user->id,
            'event_type' => 'password_reset',
            'provider' => 'email',
        ]);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        User::factory()->create([
            'email' => 'invalid-reset@example.com',
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'invalid-reset@example.com',
            'token' => 'invalid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid or expired reset token.');
    }

    public function test_profile_returns_user_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
            'phone' => '+15551234567',
            'company' => 'Acme Corp',
            'location' => 'San Francisco, CA',
            'notification_preferences' => [
                'email' => true,
                'weeklyReport' => false,
            ],
        ]);

        $response = $this->getJson('/api/auth/profile?user_id='.$user->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.name', 'Profile User')
            ->assertJsonPath('user.phone', '+15551234567')
            ->assertJsonPath('user.company', 'Acme Corp');
    }

    public function test_update_profile_updates_user_details(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $response = $this->putJson('/api/auth/profile', [
            'user_id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '+15559876543',
            'company' => 'New Co',
            'location' => 'Austin, TX',
            'notification_preferences' => [
                'email' => true,
                'weeklyReport' => true,
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.company', 'New Co');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '+15559876543',
            'company' => 'New Co',
            'location' => 'Austin, TX',
        ]);

        $this->assertDatabaseHas('user_auth_events', [
            'user_id' => $user->id,
            'event_type' => 'profile_update',
            'provider' => 'email',
        ]);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'changepw@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->putJson('/api/auth/change-password', [
            'user_id' => $user->id,
            'current_password' => 'bad-current-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Current password is incorrect.');
    }

    public function test_change_password_updates_password_and_records_event(): void
    {
        $user = User::factory()->create([
            'email' => 'changepw2@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->putJson('/api/auth/change-password', [
            'user_id' => $user->id,
            'current_password' => 'secret123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-456', $user->password));

        $this->assertDatabaseHas('user_auth_events', [
            'user_id' => $user->id,
            'event_type' => 'password_change',
            'provider' => 'email',
        ]);
    }
}
