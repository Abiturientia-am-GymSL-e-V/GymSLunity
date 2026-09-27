<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Forms\FinanceMandateText;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = ClubSetting::current();

        return Inertia::render('configuration/Finance', [
            'version' => $settings->version,
            'smallBusinessRegulationEnabled' => (bool) ($settings->data['small_business_regulation_enabled'] ?? false),
            'financeMandateText' => (string) ($settings->data['finance_mandate_text'] ?? FinanceMandateText::DEFAULT),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->mergeIfMissing([
            'finance_mandate_text' => (string) (ClubSetting::current()->data['finance_mandate_text'] ?? FinanceMandateText::DEFAULT),
        ]);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'small_business_regulation_enabled' => ['required', 'boolean'],
            'finance_mandate_text' => ['required', 'string', 'max:5000', function (string $attribute, mixed $value, \Closure $fail): void {
                preg_match_all('/\{\{[^}]+\}\}/', (string) $value, $matches);
                $unknown = array_diff(array_unique($matches[0]), ['{{verein.name}}', '{{verein.glaeubiger_id}}']);
                if ($unknown !== []) {
                    $fail('Unbekannte Platzhalter: '.implode(', ', $unknown));
                }
            }],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die Buchhaltungskonfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $values = [
                ...$settings->data,
                'small_business_regulation_enabled' => (bool) $data['small_business_regulation_enabled'],
                'finance_mandate_text' => $data['finance_mandate_text'],
            ];
            ConfigurationAudit::record($request->user(), 'Buchhaltung', $settings->data, $values);
            $settings->update(['data' => $values, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Buchhaltungskonfiguration wurde gespeichert.']);

        return to_route('configuration.finance.edit');
    }
}
