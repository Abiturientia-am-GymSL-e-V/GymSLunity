<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Communication\CommunicationTemplate;
use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\UpdatePublicPagesRequest;
use App\Models\ClubSetting;
use App\PublicSite\PublicPageTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageSettingsController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function edit(): Response
    {
        $settings = $this->clubSettings;
        $defaults = PublicPageTemplates::defaults();

        return Inertia::render('configuration/PublicPages', [
            'settings' => array_replace($defaults, Arr::only($settings->data(), array_keys($defaults))),
            'defaults' => $defaults,
            'placeholders' => PublicPageTemplates::placeholders(),
            // Only member-addressed mails know the recipient.
            'memberPlaceholders' => array_values(array_filter(
                array_column(app(CommunicationTemplate::class)->placeholders(), 'token'),
                fn (string $token): bool => ! str_starts_with($token, '{{verein.'),
            )),
            'version' => $settings->version(),
        ]);
    }

    public function update(UpdatePublicPagesRequest $request): RedirectResponse
    {
        $values = $request->validated();

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
