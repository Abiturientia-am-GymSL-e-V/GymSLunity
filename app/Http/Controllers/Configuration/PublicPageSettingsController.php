<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\PublicSite\PublicPageTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = ClubSetting::current();
        $defaults = PublicPageTemplates::defaults();

        return Inertia::render('configuration/PublicPages', [
            'settings' => array_replace($defaults, Arr::only($settings->data, array_keys($defaults))),
            'defaults' => $defaults,
            'placeholders' => PublicPageTemplates::placeholders(),
            'version' => $settings->version,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'version' => ['required', 'integer'],
            'imprint_text' => ['required', 'string', 'max:20000'],
            'privacy_text' => ['required', 'string', 'max:30000'],
            'member_access_mail_subject' => ['required', 'string', 'max:255'],
            'member_access_mail_text' => ['required', 'string', 'max:12000'],
            'join_mail_subject' => ['required', 'string', 'max:255'],
            'join_mail_text' => ['required', 'string', 'max:12000'],
            'welcome_mail_subject' => ['required', 'string', 'max:255'],
            'welcome_mail_text' => ['required', 'string', 'max:12000'],
            'contribution_invoice_mail_subject' => ['required', 'string', 'max:255'],
            'contribution_invoice_mail_text' => ['required', 'string', 'max:12000'],
        ]);
        foreach (array_keys(PublicPageTemplates::defaults()) as $key) {
            PublicPageTemplates::validate($values[$key]);
        }

        DB::transaction(function () use ($request, $values): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $values['version']) {
                throw ValidationException::withMessages(['version' => 'Die Konfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $data = array_replace($settings->data, Arr::except($values, 'version'));
            ConfigurationAudit::record($request->user(), 'Startseite und E-Mails', $settings->data, $data);
            $settings->update(['data' => $data, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Startseiten-Konfiguration gespeichert.']);

        return back();
    }
}
