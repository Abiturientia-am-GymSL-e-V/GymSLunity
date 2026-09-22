<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClubLogoController extends Controller
{
    public function show(): BinaryFileResponse
    {
        $path = ClubSetting::current()->data['logo_path'] ?? null;
        abort_unless(is_string($path) && preg_match('/^branding\/logo-[a-f0-9-]{36}\.png$/', $path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000']]);
        $source = imagecreatefromstring($data['logo']->get());
        if ($source === false) {
            throw ValidationException::withMessages(['logo' => 'Das Bild konnte nicht gelesen werden.']);
        }
        imagesavealpha($source, true);
        ob_start();
        imagepng($source, null, 7);
        $bytes = ob_get_clean();
        imagedestroy($source);
        if (! is_string($bytes)) {
            throw ValidationException::withMessages(['logo' => 'Das Bild konnte nicht gespeichert werden.']);
        }
        $path = 'branding/logo-'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $bytes);
        try {
            $oldPath = DB::transaction(function () use ($request, $data, $path): ?string {
                $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                abort_unless($request->user()->fresh()?->isAdministrator(), 403);
                if ($settings->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Die Vereinsdaten wurden inzwischen geändert. Bitte lade die Seite neu.']);
                }
                $before = $settings->data;
                $after = [...$before, 'logo_path' => $path];
                ConfigurationAudit::record($request->user(), 'Vereinslogo', $before, $after);
                $settings->update(['data' => $after, 'version' => $settings->version + 1]);

                return $before['logo_path'] ?? null;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($oldPath && str_starts_with($oldPath, 'branding/logo-')) {
            Storage::disk('local')->delete($oldPath);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vereinslogo gespeichert.']);

        return to_route('configuration.club.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $oldPath = DB::transaction(function () use ($request, $data): ?string {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die Vereinsdaten wurden inzwischen geändert. Bitte lade die Seite neu.']);
            }
            $before = $settings->data;
            $after = $before;
            unset($after['logo_path']);
            ConfigurationAudit::record($request->user(), 'Vereinslogo', $before, $after);
            $settings->update(['data' => $after, 'version' => $settings->version + 1]);

            return $before['logo_path'] ?? null;
        });
        if ($oldPath && str_starts_with($oldPath, 'branding/logo-')) {
            Storage::disk('local')->delete($oldPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vereinslogo entfernt.']);

        return to_route('configuration.club.edit');
    }
}
