<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class InstallController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if ($this->isInstalled()) {
            return to_route('login');
        }

        $this->setupToken();

        return view('install');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->isInstalled()) {
            return to_route('login');
        }

        // Deliberately inline instead of a form request: the installer must
        // redirect an installed system before validating anything.
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::default()],
            'accept' => ['accepted'],
            'setup_token' => ['required', 'string'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $expected = $this->setupToken();
            if ($expected === null || ! hash_equals($expected, trim((string) $request->input('setup_token')))) {
                $validator->errors()->add('setup_token', 'Der Einrichtungscode ist falsch.');
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except(['password', 'password_confirmation']));
        }

        try {
            if (config('database.default') === 'sqlite') {
                $database = config('database.connections.sqlite.database');
                if (is_string($database) && ! is_file($database) && @touch($database) === false) {
                    throw new \RuntimeException('Die SQLite-Datenbank konnte nicht angelegt werden.');
                }
            }

            DB::connection()->getPdo();
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Migration fehlgeschlagen: '.Artisan::output());
            }

            $data = $validator->validated();
            DB::transaction(function () use ($data): void {
                if (User::query()->exists()) {
                    throw new \RuntimeException('Es besteht bereits ein Benutzerkonto.');
                }

                $user = new User([
                    'name' => $data['name'],
                    'email' => mb_strtolower($data['email']),
                    'password' => $data['password'],
                ]);
                $user->forceFill(['roles' => ['admin']]);
                $user->markEmailAsVerified();
            });

            Artisan::call('storage:link');
            $this->replaceEnvironmentValue('SESSION_DRIVER', 'database');
            $this->replaceEnvironmentValue('CACHE_STORE', 'database');
            file_put_contents(storage_path('app/installed'), json_encode([
                'installed_at' => now()->toIso8601String(),
                'version' => trim((string) @file_get_contents(base_path('VERSION'))),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
            File::delete(storage_path('app/setup-token'));
            Artisan::call('optimize:clear');
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'installation' => app()->hasDebugModeEnabled()
                    ? $exception->getMessage()
                    : 'Die Installation konnte nicht abgeschlossen werden. Bitte Datenbankzugang, Dateirechte und das Serverprotokoll prüfen.',
            ])->withInput($request->except(['password', 'password_confirmation']));
        }

        return to_route('login')->with('status', 'Installation abgeschlossen. Anmeldung mit dem Administratorkonto; anschließend Passkey oder TOTP einrichten.');
    }

    /** Code from storage/app/setup-token, created on first use. */
    private function setupToken(): ?string
    {
        $file = storage_path('app/setup-token');
        if (! is_file($file) && is_writable(dirname($file))) {
            file_put_contents($file, bin2hex(random_bytes(12)).PHP_EOL, LOCK_EX);
            @chmod($file, 0600);
        }
        $token = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $token === '' ? null : $token;
    }

    private function isInstalled(): bool
    {
        if (is_file(storage_path('app/installed'))) {
            return true;
        }

        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function replaceEnvironmentValue(string $key, string $value): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new \RuntimeException('Die .env-Datei konnte nicht gelesen werden.');
        }

        $updated = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $contents);
        if (! is_string($updated) || file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new \RuntimeException('Die .env-Datei konnte nicht aktualisiert werden.');
        }
    }
}
