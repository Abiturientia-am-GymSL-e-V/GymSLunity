<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return [
            'driver' => 'smtp',
            'from_address' => 'verein@example.org',
            'from_name' => 'Beispielverein',
            'reply_to_address' => 'antwort@example.org',
            'reply_to_name' => 'Geschäftsstelle',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => 587,
            'smtp_security' => 'starttls',
            'smtp_username' => 'mailer',
            'smtp_password' => '',
            'clear_password' => false,
            'smtp_timeout' => 15,
            'smtp_local_domain' => 'verein.example.org',
            'sendmail_path' => '/usr/sbin/sendmail -bs -i',
            'version' => 0,
            'test_email' => '',
            ...$overrides,
        ];
    }

    public function test_only_verified_administrators_can_manage_mail_settings(): void
    {
        $this->get(route('configuration.mail.edit'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get(route('configuration.mail.edit'))->assertForbidden();
        $this->patch(route('configuration.mail.update'), $this->data())->assertForbidden();
        $this->post(route('configuration.mail.test'), $this->data(['driver' => 'log', 'test_email' => 'test@example.org']))->assertForbidden();

        $this->admin();
        $this->get(route('configuration.mail.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('configuration/Mail')
            ->where('settings.driver', 'environment')
            ->where('settings.smtp_password_configured', false)
            ->missing('settings.smtp_password')
            ->has('drivers.smtp')
            ->has('drivers.native')
            ->has('securityOptions.starttls'));
    }

    public function test_smtp_configuration_is_validated_encrypted_audited_and_applied(): void
    {
        $actor = $this->admin();
        $this->patch(route('configuration.mail.update'), $this->data(['smtp_password' => 'very-secret-password']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuration.mail.edit'));

        $settings = MailSetting::current();
        $this->assertSame('smtp', $settings->driver);
        $this->assertSame('very-secret-password', $settings->smtp_password);
        $raw = (string) DB::table('mail_settings')->where('id', 1)->value('smtp_password');
        $this->assertNotSame('very-secret-password', $raw);
        $this->assertStringNotContainsString('very-secret-password', $raw);
        $this->assertSame('database', config('mail.default'));
        $this->assertSame('smtp.example.org', config('mail.mailers.database.host'));
        $this->assertTrue((bool) config('mail.mailers.database.require_tls'));
        $this->assertDatabaseHas('configuration_changes', [
            'actor_id' => $actor->id,
            'subject' => 'E-Mail-Konfiguration',
        ]);
        $audit = DB::table('configuration_changes')->where('subject', 'E-Mail-Konfiguration')->value('after');
        $this->assertStringNotContainsString('very-secret-password', (string) $audit);

        $this->get(route('configuration.mail.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.smtp_password_configured', true)
            ->missing('settings.smtp_password'));
    }

    public function test_blank_password_keeps_existing_secret_and_clear_flag_removes_it(): void
    {
        $this->admin();
        $this->patch(route('configuration.mail.update'), $this->data(['smtp_password' => 'first-secret']))->assertSessionHasNoErrors();
        $this->patch(route('configuration.mail.update'), $this->data(['version' => 1, 'smtp_host' => 'smtp2.example.org']))->assertSessionHasNoErrors();
        $this->assertSame('first-secret', MailSetting::current()->smtp_password);

        $this->patch(route('configuration.mail.update'), $this->data(['version' => 2, 'clear_password' => true]))->assertSessionHasNoErrors();
        $this->assertNull(MailSetting::current()->smtp_password);
    }

    public function test_test_delivery_uses_unsaved_form_values_without_persisting_them(): void
    {
        $this->admin();
        $this->post(route('configuration.mail.test'), $this->data([
            'driver' => 'log',
            'from_address' => 'unsaved@example.org',
            'test_email' => 'recipient@example.org',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('environment', MailSetting::current()->driver);
        $this->assertNotSame('unsaved@example.org', MailSetting::current()->from_address);
    }

    public function test_rejects_stale_updates_unsafe_sendmail_commands_and_invalid_smtp_values(): void
    {
        $this->admin();
        $this->patch(route('configuration.mail.update'), $this->data(['driver' => 'log']))->assertSessionHasNoErrors();
        $this->patch(route('configuration.mail.update'), $this->data(['version' => 0, 'driver' => 'log']))->assertSessionHasErrors('version');
        $this->patch(route('configuration.mail.update'), $this->data([
            'version' => 1,
            'driver' => 'sendmail',
            'sendmail_path' => '/usr/sbin/sendmail -bs; touch /tmp/unsafe',
        ]))->assertSessionHasErrors('sendmail_path');
        $this->patch(route('configuration.mail.update'), $this->data([
            'version' => 1,
            'smtp_port' => 70000,
            'smtp_local_domain' => 'bad domain',
        ]))->assertSessionHasErrors(['smtp_port', 'smtp_local_domain']);
    }
}
