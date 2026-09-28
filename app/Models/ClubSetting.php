<?php

declare(strict_types=1);

namespace App\Models;

use App\Configuration\ClubSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** @property array<string, mixed> $data */
class ClubSetting extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer', 'fields_version' => 'integer'];
    }

    protected static function booted(): void
    {
        // increment() fires "updated" but not "saved".
        static::saved(fn () => app(ClubSettings::class)->refresh());
        static::updated(fn () => app(ClubSettings::class)->refresh());
    }

    /** Prefer injecting App\Configuration\ClubSettings for reads. */
    public static function current(): self
    {
        return app(ClubSettings::class)->model();
    }

    public function logoPath(): ?string
    {
        $path = $this->data['logo_path'] ?? null;
        if (! is_string($path) || ! preg_match('/^branding\/logo-[a-f0-9-]{36}\.png$/', $path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    public function logoDataUri(): ?string
    {
        $path = $this->logoPath();

        if ($path === null) {
            return null;
        }

        // A logo can disappear or become unreadable after the existence check.
        $contents = @file_get_contents($path);

        return $contents === false ? null : 'data:image/png;base64,'.base64_encode($contents);
    }
}
