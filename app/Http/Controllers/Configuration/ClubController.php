<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubData;
use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\UpdateClubRequest;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClubController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function edit(): Response
    {
        $settings = $this->clubSettings;

        return Inertia::render('configuration/Club', ['club' => $settings->data(), 'version' => $settings->version(), 'fields' => ClubData::fields()]);
    }

    public function update(UpdateClubRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die Vereinsdaten wurden inzwischen geändert. Bitte die Seite neu laden.']);
            }
            $clubKeys = array_column(ClubData::fields(), 'key');
            $values = [...Arr::except($settings->data, $clubKeys), ...Arr::except($data, 'version')];
            ConfigurationAudit::record($request->user(), 'Vereinsdaten', $settings->data, $values);
            $settings->update(['data' => $values, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vereinsdaten gespeichert.']);

        return to_route('configuration.club.edit');
    }
}
