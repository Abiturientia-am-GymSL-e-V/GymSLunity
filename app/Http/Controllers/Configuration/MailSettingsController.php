<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Configuration\MailConfigurator;
use App\Configuration\MailSettingsData;
use App\Http\Controllers\Controller;
use App\Mail\ConfigurationTestMail;
use App\Models\MailSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = MailSetting::current();

        return Inertia::render('configuration/Mail', [
            'settings' => $settings->publicData(),
            'drivers' => MailSettingsData::DRIVERS,
            'securityOptions' => MailSettingsData::SECURITY,
            'environmentDriver' => config('mail.environment_default', 'log'),
            'nativeAvailable' => (bool) ini_get('sendmail_path') || DIRECTORY_SEPARATOR === '\\',
        ]);
    }

    public function update(Request $request, MailConfigurator $configurator): RedirectResponse
    {
        $data = MailSettingsData::normalize($request->validate(MailSettingsData::rules()));
        DB::transaction(function () use ($request, $data): void {
            $settings = MailSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die E-Mail-Konfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $before = $settings->auditData();
            $settings->fill(Arr::only($data, [
                'driver', 'from_address', 'from_name', 'reply_to_address', 'reply_to_name',
                'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_timeout',
                'smtp_local_domain', 'sendmail_path',
            ]));
            if (! empty($data['smtp_password'])) {
                $settings->smtp_password = $data['smtp_password'];
            } elseif ($data['clear_password']) {
                $settings->smtp_password = null;
            }
            $settings->version++;
            $settings->save();
            ConfigurationAudit::record($request->user(), 'E-Mail-Konfiguration', $before, $settings->auditData());
        });
        $configurator->applyStored();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'E-Mail-Konfiguration gespeichert.']);

        return to_route('configuration.mail.edit');
    }

    public function test(Request $request, MailConfigurator $configurator): RedirectResponse
    {
        $data = MailSettingsData::normalize($request->validate(MailSettingsData::rules(true)));
        $stored = MailSetting::current();
        $password = $data['clear_password']
            ? null
            : ((isset($data['smtp_password']) && $data['smtp_password'] !== '') ? $data['smtp_password'] : $stored->smtp_password);
        $mailerName = 'configuration-test';
        $manager = $configurator->manager();
        try {
            config(['mail.mailers.'.$mailerName => $configurator->transientConfig($data, $password)]);
            $manager->purge($mailerName);
            $mailer = $manager->mailer($mailerName);
            $mailer->alwaysFrom($data['from_address'], $data['from_name']);
            if (! empty($data['reply_to_address'])) {
                $mailer->alwaysReplyTo($data['reply_to_address'], $data['reply_to_name'] ?? null);
            }
            $mailer->to($data['test_email'])->send(new ConfigurationTestMail(
                MailSettingsData::DRIVERS[$data['driver']],
                $data['test_email'],
            ));
        } catch (Throwable $exception) {
            $message = preg_replace('/[\r\n]+/', ' ', $exception->getMessage()) ?? 'Unbekannter Transportfehler';
            if ($password) {
                $message = str_replace($password, '***', $message);
            }
            throw ValidationException::withMessages([
                'test_email' => 'Testversand fehlgeschlagen: '.mb_substr($message, 0, 500),
            ]);
        } finally {
            $manager->purge($mailerName);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test-E-Mail wurde an '.$data['test_email'].' übergeben.']);

        return back();
    }
}
