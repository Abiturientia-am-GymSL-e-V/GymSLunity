<?php

namespace App\Configuration;

use Illuminate\Validation\Rule;

final class MailSettingsData
{
    public const DRIVERS = [
        'environment' => 'Serverumgebung (.env)',
        'smtp' => 'SMTP-Server',
        'native' => 'PHP mail() / php.ini',
        'sendmail' => 'Sendmail / Postfix',
        'log' => 'Nur protokollieren (Entwicklung)',
    ];

    public const SECURITY = [
        'auto' => 'Automatisch (STARTTLS, wenn verfügbar)',
        'starttls' => 'STARTTLS erforderlich',
        'tls' => 'TLS/SSL direkt',
        'none' => 'Unverschlüsselt',
    ];

    /** @return array<string, list<mixed>> */
    public static function rules(bool $test = false): array
    {
        return [
            'driver' => ['required', Rule::in(array_keys(self::DRIVERS))],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'reply_to_address' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'smtp_host' => ['nullable', 'required_if:driver,smtp', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'smtp_port' => ['nullable', 'required_if:driver,smtp', 'integer', 'between:1,65535'],
            'smtp_security' => ['required', Rule::in(array_keys(self::SECURITY))],
            'smtp_username' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'clear_password' => ['required', 'boolean'],
            'smtp_timeout' => ['required', 'integer', 'between:1,120'],
            'smtp_local_domain' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'sendmail_path' => [
                'nullable',
                'required_if:driver,sendmail',
                'string',
                'max:255',
                'regex:/^\/(?:[A-Za-z0-9._-]+\/)*sendmail(?:\s+-(?:bs|t|i|oi))*$/',
            ],
            'version' => [$test ? 'nullable' : 'required', 'integer', 'min:0'],
            'test_email' => [$test ? 'required' : 'nullable', 'email:rfc', 'max:255'],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        foreach (['from_address', 'reply_to_address'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = mb_strtolower(trim($data[$key]));
            }
        }
        foreach (['from_name', 'reply_to_name', 'smtp_host', 'smtp_username', 'smtp_local_domain', 'sendmail_path'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]) ?: null;
            }
        }
        if (($data['smtp_security'] ?? null) === 'tls' && empty($data['smtp_port'])) {
            $data['smtp_port'] = 465;
        }

        return $data;
    }
}
