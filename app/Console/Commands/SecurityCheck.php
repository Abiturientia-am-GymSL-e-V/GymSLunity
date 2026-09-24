<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Throwable;

class SecurityCheck extends Command
{
    protected $signature = 'security:check {--json : Ausgabe als JSON}';

    protected $description = 'Prüft eine Produktivkonfiguration auf sicherheitskritische Einstellungen';

    public function handle(): int
    {
        $timeoutMinutes = (int) ceil((int) config('security.inactivity_timeout', 1800) / 60);
        $checks = [
            ['name' => 'production_environment', 'ok' => app()->isProduction(), 'message' => 'APP_ENV muss in Produktion auf production stehen.'],
            ['name' => 'debug_disabled', 'ok' => ! (bool) config('app.debug'), 'message' => 'APP_DEBUG muss deaktiviert sein.'],
            ['name' => 'strong_app_key', 'ok' => $this->validKey((string) config('app.key')), 'message' => 'APP_KEY muss ein zufälliger Schlüssel mit mindestens 256 Bit sein.'],
            ['name' => 'password_hashing', 'ok' => config('hashing.driver') !== 'bcrypt' || (int) config('hashing.bcrypt.rounds') >= 12, 'message' => 'BCRYPT_ROUNDS muss in Produktion mindestens 12 betragen.'],
            ['name' => 'https_url', 'ok' => str_starts_with((string) config('app.url'), 'https://'), 'message' => 'APP_URL muss HTTPS verwenden.'],
            ['name' => 'persistent_cache', 'ok' => ! in_array(config('cache.default'), ['array', 'null'], true), 'message' => 'CACHE_STORE muss für verteiltes Rate-Limiting persistent sein.'],
            ['name' => 'manageable_sessions', 'ok' => config('session.driver') === 'database', 'message' => 'SESSION_DRIVER muss database sein, damit aktive Sitzungen einzeln widerrufen werden können.'],
            ['name' => 'encrypted_sessions', 'ok' => (bool) config('session.encrypt'), 'message' => 'SESSION_ENCRYPT muss aktiviert sein.'],
            ['name' => 'secure_cookie', 'ok' => (bool) config('session.secure'), 'message' => 'SESSION_SECURE_COOKIE muss aktiviert sein.'],
            ['name' => 'session_timeout', 'ok' => (int) config('session.lifetime') <= $timeoutMinutes, 'message' => 'SESSION_LIFETIME darf das Sicherheits-Inaktivitätslimit nicht überschreiten.'],
            ['name' => 'security_headers', 'ok' => (bool) config('security.headers.enabled'), 'message' => 'SECURITY_HEADERS_ENABLED muss aktiviert sein.'],
            ['name' => 'two_factor', 'ok' => Features::enabled(Features::twoFactorAuthentication()), 'message' => 'Fortify-Zwei-Faktor-Authentifizierung muss aktiviert sein.'],
            ['name' => 'queued_work', 'ok' => config('queue.default') !== 'sync', 'message' => 'QUEUE_CONNECTION sollte für verlässliche Hintergrundarbeit nicht sync sein.'],
            ['name' => 'real_mail_transport', 'ok' => ! in_array(config('mail.default'), ['log', 'array'], true), 'message' => 'MAIL_MAILER darf in Produktion nicht log oder array sein.'],
            ['name' => 'audit_schema', 'ok' => $this->auditTableExists(), 'message' => 'Die Migration für security_audit_events muss ausgeführt sein.'],
        ];
        $failed = collect($checks)->where('ok', false)->values();

        if ($this->option('json')) {
            $this->line(json_encode(['production' => app()->isProduction(), 'ok' => $failed->isEmpty(), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check) {
                $line = ($check['ok'] ? '<fg=green>OK</>' : '<fg=red>FEHLER</>').' '.$check['name'];
                $this->line($line.($check['ok'] ? '' : ': '.$check['message']));
            }
            $this->newLine();
            $this->line($failed->isEmpty() ? '<fg=green>Keine unsichere Produktivkonfiguration erkannt.</>' : '<fg=red>'.$failed->count().' Prüfung(en) fehlgeschlagen.</>');
        }

        return app()->isProduction() && $failed->isNotEmpty() ? self::FAILURE : self::SUCCESS;
    }

    private function validKey(string $key): bool
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && strlen($decoded) >= 32;
        }

        return strlen($key) >= 32;
    }

    private function auditTableExists(): bool
    {
        try {
            return Schema::hasTable('security_audit_events');
        } catch (Throwable) {
            return false;
        }
    }
}
