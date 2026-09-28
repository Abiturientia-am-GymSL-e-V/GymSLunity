<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function __invoke(): Response
    {
        $cache = (string) config('cache.default');
        $session = (string) config('session.driver');
        $queue = (string) config('queue.default');
        $redisInUse = in_array('redis', [$cache, $session, $queue], true);
        $url = (string) config('app.url');
        $production = app()->isProduction();

        return Inertia::render('configuration/System', [
            'runtime' => [
                'productName' => (string) config('app.name'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => $url,
                'displayTimezone' => (string) config('app.display_timezone'),
                'locale' => (string) config('app.locale'),
                'database' => (string) config('database.default'),
                'cache' => $cache,
                'session' => $session,
                'sessionEncrypted' => (bool) config('session.encrypt'),
                'secureCookie' => (bool) config('session.secure'),
                'queue' => $queue,
                'mail' => (string) config('mail.default'),
            ],
            'redis' => [
                'inUse' => $redisInUse,
                'client' => (string) config('database.redis.client'),
                'available' => extension_loaded('redis') || class_exists('Predis\\Client'),
                'hostConfigured' => filled(config('database.redis.default.host')) || filled(config('database.redis.default.url')),
            ],
            'backupError' => session('backup_error'),
            'checks' => [
                [
                    'label' => 'Produktivmodus',
                    'ok' => $production,
                    'detail' => $production ? 'APP_ENV=production' : 'Für den öffentlichen Betrieb APP_ENV=production setzen.',
                ],
                [
                    'label' => 'Debug-Ausgaben',
                    'ok' => ! config('app.debug'),
                    'detail' => config('app.debug') ? 'APP_DEBUG muss öffentlich deaktiviert sein.' : 'APP_DEBUG ist deaktiviert.',
                ],
                [
                    'label' => 'Öffentliche URL',
                    'ok' => Str::startsWith($url, 'https://'),
                    'detail' => Str::startsWith($url, 'https://') ? 'APP_URL verwendet HTTPS.' : 'APP_URL sollte die öffentliche HTTPS-Adresse enthalten.',
                ],
                [
                    'label' => 'Sitzungscookie',
                    'ok' => (bool) config('session.secure'),
                    'detail' => config('session.secure') ? 'Cookies werden nur über HTTPS übertragen.' : 'Für HTTPS SESSION_SECURE_COOKIE=true setzen.',
                ],
                [
                    'label' => 'Sitzungsverschlüsselung',
                    'ok' => (bool) config('session.encrypt'),
                    'detail' => config('session.encrypt') ? 'Sitzungsdaten werden verschlüsselt.' : 'SESSION_ENCRYPT=true wird empfohlen.',
                ],
                [
                    'label' => 'E-Mail-Versand',
                    'ok' => config('mail.default') !== 'log',
                    'detail' => config('mail.default') === 'log' ? 'Der Log-Mailer stellt keine Nachrichten zu.' : 'Ein zustellender Mail-Transport ist ausgewählt.',
                ],
                [
                    'label' => 'Redis-Unterstützung',
                    'ok' => ! $redisInUse || extension_loaded('redis') || class_exists('Predis\\Client'),
                    'detail' => $redisInUse
                        ? 'Redis wird von mindestens einem Treiber verwendet und benötigt einen erreichbaren Server sowie einen PHP-Client.'
                        : 'Redis ist optional; Datenbanktreiber sind für Einzelserver ausreichend.',
                ],
            ],
        ]);
    }
}
