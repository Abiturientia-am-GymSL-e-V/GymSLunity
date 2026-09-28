<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Configuration\SoftwareModules;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SoftwareModuleController extends Controller
{
    public function edit(): Response
    {
        $settings = ClubSetting::current();

        return Inertia::render('configuration/SoftwareModules', [
            'modules' => SoftwareModules::values($settings),
            'definitions' => SoftwareModules::OPTIONAL,
            'version' => $settings->version,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $moduleKeys = array_keys(SoftwareModules::OPTIONAL);
        $rules = ['version' => ['required', 'integer'], 'modules' => ['required', 'array:'.implode(',', $moduleKeys)]];
        foreach ($moduleKeys as $key) {
            $rules['modules.'.$key] = ['required', 'boolean'];
        }
        $values = $request->validate($rules);

        DB::transaction(function () use ($request, $values): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $values['version']) {
                throw ValidationException::withMessages(['version' => 'Die Konfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $before = SoftwareModules::values($settings);
            $data = [...$settings->data, 'software_modules' => $values['modules']];
            ConfigurationAudit::record($request->user(), 'Softwaremodule', $before, $values['modules']);
            $settings->update(['data' => $data, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Softwaremodule gespeichert.']);

        return back();
    }
}
