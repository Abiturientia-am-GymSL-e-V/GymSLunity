<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Support\SessionKey;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailTransportErrorTest extends TestCase
{
    // Web middleware sends unmigrated installations to the installer.
    use RefreshDatabase;

    public function test_rejected_smtp_login_returns_to_the_form_with_a_hint(): void
    {
        $log = Log::spy();
        Route::middleware('web')->post('/_testing/mail-failure', fn () => throw new TransportException('535 5.7.8 Error: authentication failed', 535));

        $this->from('/_testing/form')
            ->post('/_testing/mail-failure')
            ->assertRedirect('/_testing/form')
            ->assertSessionHas(SessionKey::FLASH_DATA.'.toast', fn (array $toast): bool => $toast['type'] === 'error'
                && str_contains($toast['message'], 'Anmeldung abgelehnt'));

        $log->shouldHaveReceived('error')->once();
    }

    public function test_unreachable_mail_server_is_reported_as_json_for_api_clients(): void
    {
        Route::middleware('web')->post('/_testing/mail-failure', fn () => throw new TransportException('Connection could not be established'));

        $this->postJson('/_testing/mail-failure')
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'nicht erreichbar'));
    }
}
