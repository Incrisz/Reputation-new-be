<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $testEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testEnvPath = storage_path('framework/testing-admin-settings-'.uniqid().'.env');
        File::ensureDirectoryExists(dirname($this->testEnvPath));
        File::put($this->testEnvPath, implode(PHP_EOL, [
            'MAIL_HOST=old-mail.example.com',
            'MAIL_PORT=2525',
            'STRIPE_SECRET_KEY=old_secret',
            'GOOGLE_CLIENT_ID=old-client-id',
            '',
        ]));

        config()->set('admin.settings_env_file', $this->testEnvPath);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testEnvPath)) {
            File::delete($this->testEnvPath);
        }

        parent::tearDown();
    }

    public function test_admin_can_view_grouped_env_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->getJson('/api/admin/settings/env?admin_user_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('settings.mail.MAIL_HOST', 'old-mail.example.com')
            ->assertJsonPath('settings.mail.MAIL_PORT', '2525')
            ->assertJsonPath('settings.stripe.STRIPE_SECRET_KEY', 'old_secret')
            ->assertJsonPath('settings.google_auth.GOOGLE_CLIENT_ID', 'old-client-id');
    }

    public function test_admin_can_update_env_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->putJson('/api/admin/settings/env', [
            'admin_user_id' => $admin->id,
            'mail' => [
                'MAIL_HOST' => 'smtp.privateemail.com',
                'MAIL_PORT' => '465',
            ],
            'stripe' => [
                'STRIPE_SECRET_KEY' => 'new_secret_key',
            ],
            'google_auth' => [
                'GOOGLE_CLIENT_ID' => 'new-client-id',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('settings.mail.MAIL_HOST', 'smtp.privateemail.com')
            ->assertJsonPath('settings.stripe.STRIPE_SECRET_KEY', 'new_secret_key');

        $this->assertStringContainsString('MAIL_HOST=smtp.privateemail.com', (string) File::get($this->testEnvPath));
        $this->assertStringContainsString('MAIL_PORT=465', (string) File::get($this->testEnvPath));
        $this->assertStringContainsString('STRIPE_SECRET_KEY=new_secret_key', (string) File::get($this->testEnvPath));
        $this->assertStringContainsString('GOOGLE_CLIENT_ID=new-client-id', (string) File::get($this->testEnvPath));
    }
}

