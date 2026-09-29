<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $encrypted_signature
 * @property string|null $signature_mime
 * @property list<string>|null $roles
 * @property bool $is_active
 * @property int $lock_version
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'encrypted_signature', 'signature_mime', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    protected $attributes = ['is_active' => true, 'lock_version' => 0];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->is_active && in_array('admin', $this->roles ?? [], true);
    }

    public function hasRequiredSecondFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null || $this->hasPasskeysEnabled();
    }

    public function hasProfileSignature(): bool
    {
        return is_string($this->encrypted_signature) && $this->encrypted_signature !== '';
    }

    public function profileSignature(): ?string
    {
        if (! $this->hasProfileSignature()) {
            return null;
        }

        $signature = base64_decode(Crypt::decryptString($this->encrypted_signature), true);

        return is_string($signature) ? $signature : null;
    }

    /**
     * Signs the account out everywhere except the given session, e.g. after
     * a password change, so a stolen session does not outlive the old password.
     */
    public function endOtherSessions(?string $keepSessionId = null): void
    {
        $table = (string) config('session.table', 'sessions');
        if (config('session.driver') === 'database' && Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $this->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '<>', $keepSessionId))
                ->delete();
        }
        $this->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
