<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $driver
 * @property string $from_address
 * @property string $from_name
 * @property string|null $reply_to_address
 * @property string|null $reply_to_name
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string $smtp_security
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property int $smtp_timeout
 * @property string|null $smtp_local_domain
 * @property string $sendmail_path
 * @property int $version
 */
class MailSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'smtp_port' => 'integer',
            'smtp_timeout' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->whereKey(1)->firstOrFail();
    }

    /** @return array<string, string|int|bool|null> */
    public function publicData(): array
    {
        return [
            'driver' => $this->driver,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'reply_to_address' => $this->reply_to_address,
            'reply_to_name' => $this->reply_to_name,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_security' => $this->smtp_security,
            'smtp_username' => $this->smtp_username,
            'smtp_password_configured' => $this->smtp_password !== null,
            'smtp_timeout' => $this->smtp_timeout,
            'smtp_local_domain' => $this->smtp_local_domain,
            'sendmail_path' => $this->sendmail_path,
            'version' => $this->version,
        ];
    }

    /** @return array<string, string|int|bool|null> */
    public function auditData(): array
    {
        return $this->publicData();
    }
}
