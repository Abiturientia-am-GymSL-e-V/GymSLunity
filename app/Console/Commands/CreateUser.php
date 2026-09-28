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
    protected $signature = 'app:create-user {--role=* : Rolle (admin, vereinsverwaltung, mv, auditor, bh, bv, kp)}';

    protected $description = 'Ein Benutzerkonto für die geschlossene Entwicklungsinstanz anlegen';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Name'),
            'email' => strtolower(trim((string) $this->ask('E-Mail-Adresse'))),
            'password' => $this->secret('Passwort (mindestens 12 Zeichen)'),
            'password_confirmation' => $this->secret('Passwort wiederholen'),
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
}
