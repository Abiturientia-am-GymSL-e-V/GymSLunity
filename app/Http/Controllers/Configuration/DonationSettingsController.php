<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Donations\DonationCertificateGenerator;
use App\Donations\DonationPurposes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\UpdateDonationSettingsRequest;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DonationSettingsController extends Controller
{
    private const KEYS = [
        'donation_purpose_codes', 'contributions_tax_deductible', 'tax_privilege_notice_type',
        'tax_privilege_notice_date', 'tax_privilege_assessment_period', 'tax_privilege_notice_location',
        'certificate_machine_generated_notified',
    ];

    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function edit(): Response
    {
        $settings = $this->clubSettings;

        return Inertia::render('configuration/Donations', [
            'settings' => Arr::only($settings->data(), self::KEYS),
            'version' => $settings->version(),
            'purposes' => DonationPurposes::forFrontend(),
            'club' => Arr::only($settings->data(), ['name', 'street', 'postal_code', 'city', 'register_number', 'register_court', 'tax_number', 'tax_office']),
            'readiness' => DonationCertificateGenerator::configurationErrors($settings->data()),
        ]);
    }

    public function update(UpdateDonationSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die Stammdaten wurden inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $values = [...$settings->data, ...Arr::except($data, 'version')];
            ConfigurationAudit::record($request->user(), 'Spenden & Zuwendungsbestätigungen', $settings->data, $values);
            $settings->update(['data' => $values, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Spenden-Stammdaten wurden gespeichert.']);

        return to_route('configuration.donations.edit');
    }
}
