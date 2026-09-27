<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function browserErrorPages(): array
    {
        return [
            'generic client error' => [400, 'Anfrage nicht möglich'],
            'unauthorized' => [401, 'Nicht autorisiert'],
            'payment required' => [402, 'Zahlung erforderlich'],
            'forbidden' => [403, 'Zugriff verweigert'],
            'not found' => [404, 'Seite nicht gefunden'],
            'generic method error' => [405, 'Anfrage nicht möglich'],
            'expired' => [419, 'Seite abgelaufen'],
            'unprocessable' => [422, 'Anfrage nicht verarbeitbar'],
            'rate limited' => [429, 'Zu viele Anfragen'],
            'server error' => [500, 'Interner Serverfehler'],
            'generic server error' => [502, 'Technischer Fehler'],
            'unavailable' => [503, 'Dienst nicht verfügbar'],
        ];
    }

    #[DataProvider('browserErrorPages')]
    public function test_browser_errors_use_the_shared_design(int $status, string $title): void
    {
        Route::get('/_testing/error-page', fn () => abort($status));

        $this->get('/_testing/error-page')
            ->assertStatus($status)
            ->assertSee('FEHLER '.$status)
            ->assertSee($title)
            ->assertSee('Zur Startseite')
            ->assertSee('href="'.route('home').'"', false);
    }

    public function test_json_errors_remain_json(): void
    {
        Route::get('/_testing/json-error', fn () => abort(404));

        $this->getJson('/_testing/json-error')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    }
}
