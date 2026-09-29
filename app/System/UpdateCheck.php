<?php

declare(strict_types=1);

namespace App\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Compares the installed version with the releases published on GitHub.
 * The result is cached, so the release list is fetched at most every few
 * hours; the check can be disabled with GYMSLUNITY_UPDATE_CHECK=false.
 */
final class UpdateCheck
{
    private const CACHE_KEY = 'system.update-check';

    private const CACHE_HOURS = 6;

    public static function installedVersion(): string
    {
        return trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unbekannt';
    }

    /** @return array{installed: string, status: string, latest: array{version: string, url: string, published_at: string|null, prerelease: bool}|null, checked_at: string|null, releases_url: string} */
    public function status(): array
    {
        $installed = self::installedVersion();
        $base = ['installed' => $installed, 'latest' => null, 'checked_at' => null, 'releases_url' => $this->repositoryUrl().'/releases'];
        if (! config('app.update_check')) {
            return [...$base, 'status' => 'disabled'];
        }

        $latest = Cache::remember(self::CACHE_KEY, now()->addHours(self::CACHE_HOURS), fn (): array => $this->fetch($installed));
        if (($latest['version'] ?? null) === null) {
            return [...$base, 'status' => 'unknown', 'checked_at' => $latest['checked_at'] ?? null];
        }

        return [
            ...$base,
            'status' => $this->isValid($installed) && version_compare(self::normalize($latest['version']), self::normalize($installed), '>') ? 'update' : 'current',
            'latest' => ['version' => $latest['version'], 'url' => $latest['url'], 'published_at' => $latest['published_at'], 'prerelease' => $latest['prerelease']],
            'checked_at' => $latest['checked_at'],
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Newest release that fits the installed channel: an installed pre-release
     * is also offered newer pre-releases, a stable version only stable ones.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $installed): array
    {
        $checkedAt = Carbon::now()->toIso8601String();
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'GymSLunity/'.$installed])
                ->timeout(5)
                ->get('https://api.github.com/repos/'.config('app.repository').'/releases', ['per_page' => 20]);
            if (! $response->successful() || ! is_array($response->json())) {
                return ['version' => null, 'checked_at' => $checkedAt];
            }
            $allowPrerelease = str_contains($installed, '-');
            $releases = collect($response->json())
                ->filter(fn ($release): bool => is_array($release) && empty($release['draft']) && is_string($release['tag_name'] ?? null)
                    && ($allowPrerelease || empty($release['prerelease'])) && $this->isValid(ltrim($release['tag_name'], 'v')))
                ->sort(fn (array $a, array $b): int => version_compare(self::normalize(ltrim($b['tag_name'], 'v')), self::normalize(ltrim($a['tag_name'], 'v'))));
            $release = $releases->first();
        } catch (Throwable $exception) {
            report($exception);

            return ['version' => null, 'checked_at' => $checkedAt];
        }

        return $release === null ? ['version' => null, 'checked_at' => $checkedAt] : [
            'version' => ltrim($release['tag_name'], 'v'),
            'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : $this->repositoryUrl().'/releases',
            'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
            'prerelease' => (bool) ($release['prerelease'] ?? false),
            'checked_at' => $checkedAt,
        ];
    }

    private function repositoryUrl(): string
    {
        return 'https://github.com/'.config('app.repository');
    }

    private function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    /** "1.0.0-beta.1" → "1.0.0-beta1": version_compare() treats dots in suffixes as separate parts. */
    private static function normalize(string $version): string
    {
        return (string) preg_replace('/-([a-z]+)\.(\d+)/i', '-$1$2', $version);
    }
}
