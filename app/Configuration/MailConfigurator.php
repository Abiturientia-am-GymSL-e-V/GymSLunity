<?php

declare(strict_types=1);

namespace App\Configuration;

use App\Models\MailSetting;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;

final class MailConfigurator
{
    public const MAILER = 'database';

    public function applyStored(): void
    {
        $this->apply(MailSetting::current());
    }

    public function apply(MailSetting $settings): void
    {
        config([
            'mail.from' => ['address' => $settings->from_address, 'name' => $settings->from_name],
            'mail.reply_to' => $settings->reply_to_address
                ? ['address' => $settings->reply_to_address, 'name' => $settings->reply_to_name]
                : null,
        ]);

        if ($settings->driver === 'environment') {
            config(['mail.default' => config('mail.environment_default', 'log')]);
        } else {
            config([
                'mail.default' => self::MAILER,
                'mail.mailers.'.self::MAILER => $this->transportConfig($settings),
            ]);
        }
        if (! Mail::isFake()) {
            $this->manager()->forgetMailers();
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transientConfig(array $data, ?string $password): array
    {
        return match ($data['driver']) {
            'smtp' => [
                'transport' => 'smtp',
                'scheme' => $data['smtp_security'] === 'tls' ? 'smtps' : 'smtp',
                'host' => $data['smtp_host'],
                'port' => (int) $data['smtp_port'],
                'username' => $data['smtp_username'] ?: null,
                'password' => $password,
                'timeout' => (int) $data['smtp_timeout'],
                'local_domain' => $data['smtp_local_domain'] ?: null,
                'auto_tls' => $data['smtp_security'] !== 'none',
                'require_tls' => $data['smtp_security'] === 'starttls',
                'verify_peer' => true,
            ],
            'native' => ['transport' => 'native'],
            'sendmail' => ['transport' => 'sendmail', 'path' => $data['sendmail_path']],
            'log' => ['transport' => 'log'],
            default => config('mail.mailers.'.config('mail.environment_default', 'log'), ['transport' => 'log']),
        };
    }

    /** @return array<string, mixed> */
    private function transportConfig(MailSetting $settings): array
    {
        return $this->transientConfig([
            'driver' => $settings->driver,
            'smtp_security' => $settings->smtp_security,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_timeout' => $settings->smtp_timeout,
            'smtp_local_domain' => $settings->smtp_local_domain,
            'sendmail_path' => $settings->sendmail_path,
        ], $settings->smtp_password);
    }

    public function manager(): MailManager
    {
        return app('mail.manager');
    }
}
