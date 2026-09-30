<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Configuration\UserRoles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'app:create-user
        {--role=* : Rolle (admin, vereinsverwaltung, mv, auditor, bh, bv, kp)}
        {--name= : Name ohne Rückfrage}
        {--email= : E-Mail-Adresse ohne Rückfrage}
        {--password-file= : Passwort aus der ersten Zeile dieser Datei lesen, "-" liest die Standardeingabe}';

    protected $description = 'Ein Benutzerkonto für die geschlossene Entwicklungsinstanz anlegen';

    public function handle(): int
    {
        $password = $this->passwordFromFile();
        if ($password === false) {
            $this->error('Das Passwort konnte nicht aus '.$this->option('password-file').' gelesen werden.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? $this->ask('Name');
        $email = $this->option('email') ?? $this->ask('E-Mail-Adresse');
        $data = [
            'name' => $name,
            'email' => strtolower(trim((string) $email)),
            'password' => $password ?? $this->secret('Passwort (mindestens 12 Zeichen)'),
            'password_confirmation' => $password ?? $this->secret('Passwort wiederholen'),
            'roles' => $this->option('role'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::default()],
            'roles' => ['array'],
            'roles.*' => [Rule::in(array_keys(UserRoles::LABELS))],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($validator->safe()->only(['name', 'email', 'password']));
        $user->forceFill($validator->safe()->only(['roles']));
        $user->markEmailAsVerified();

        $this->info('Benutzerkonto angelegt. Anmeldung ist mit E-Mail-Adresse und Passwort möglich.');

        return self::SUCCESS;
    }

    /**
     * Keeps the password out of the process list and shell history when the
     * account is created by a script. Null when the option is not used.
     */
    private function passwordFromFile(): string|false|null
    {
        $file = $this->option('password-file');
        if (! is_string($file) || $file === '') {
            return null;
        }

        $content = @file_get_contents($file === '-' ? 'php://stdin' : $file);
        if ($content === false) {
            return false;
        }

        return rtrim(explode("\n", $content, 2)[0], "\r");
    }
}
