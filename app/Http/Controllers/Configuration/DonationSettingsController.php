<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Donations\DonationCertificateGenerator;
use App\Donations\DonationPurposes;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DonationSettingsController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    private const KEYS = [
        'donation_purpose_codes', 'contributions_tax_deductible', 'tax_privilege_notice_type',
        'tax_privilege_notice_date', 'tax_privilege_assessment_period',
        'certificate_machine_generated_notified',
    ];

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

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'donation_purpose_codes' => ['required', 'array', 'min:1'],
            'donation_purpose_codes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DonationPurposes::options()))],
            'contributions_tax_deductible' => ['required', 'boolean'],
            'tax_privilege_notice_type' => ['required', Rule::in(['exemption_notice', 'corporate_tax_attachment', 'section_60a_notice'])],
            'tax_privilege_notice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tax_privilege_assessment_period' => ['nullable', 'required_unless:tax_privilege_notice_type,section_60a_notice', 'string', 'max:30'],
            'certificate_machine_generated_notified' => ['required', 'boolean'],
        ]);
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
