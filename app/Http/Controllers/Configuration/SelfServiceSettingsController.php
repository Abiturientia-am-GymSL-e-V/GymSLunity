<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\UpdateSelfServiceSettingsRequest;
use App\Models\ClubSetting;
use App\SelfService\EmailAddressFilter;
use App\SelfService\FormTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SelfServiceSettingsController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function edit(): Response
    {
        $settings = $this->clubSettings;

        return Inertia::render('configuration/SelfService', [
            'settings' => array_replace(
                ['selfservice_enabled' => false, 'public_join_enabled' => false, 'membership_activation' => 'immediate', 'email_filter_mode' => 'off', 'email_filter_patterns' => ''],
                FormTemplates::defaults(),
                Arr::only($settings->data(), ['selfservice_enabled', 'public_join_enabled', 'membership_activation', 'email_filter_mode', 'email_filter_patterns', ...array_keys(FormTemplates::defaults())]),
            ),
            'version' => $settings->version(), 'defaults' => FormTemplates::defaults(), 'placeholders' => FormTemplates::placeholders(),
        ]);
    }

    public function update(UpdateSelfServiceSettingsRequest $request): RedirectResponse
    {
        $values = $request->validated();
        $values['email_filter_patterns'] = implode("\n", EmailAddressFilter::parse((string) $values['email_filter_patterns']));
        DB::transaction(function () use ($request, $values): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $values['version']) {
                throw ValidationException::withMessages(['version' => 'Die Konfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $data = array_replace($settings->data, Arr::except($values, 'version'));
            ConfigurationAudit::record($request->user(), 'Selfservice & Formulare', $settings->data, $data);
            $settings->update(['data' => $data, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Selfservice-Konfiguration gespeichert.']);

        return back();
    }
}
