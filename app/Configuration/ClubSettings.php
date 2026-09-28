<?php

declare(strict_types=1);

namespace App\Configuration;

use App\Models\ClubSetting;

/**
 * Request-scoped access to the single club configuration row.
 *
 * Reads go through this service so the row is loaded once per request and
 * derived rules (display name, logo URL, SEPA readiness) exist in one place.
 * Writes that must be serialized keep locking the row directly via
 * ClubSetting::query()->lockForUpdate(); ClubSetting model events refresh
 * this cache afterwards.
 */
final class ClubSettings
{
    /** Club fields the SEPA direct debit XML generators require. */
    public const SEPA_FIELDS = ['name' => 'Vereinsname', 'iban' => 'Vereins-IBAN', 'creditor_id' => 'SEPA-Gläubiger-ID'];

    /** Club fields printed on a SEPA mandate. */
    public const MANDATE_FIELDS = ['name', 'street', 'postal_code', 'city', 'country', 'creditor_id'];

    private ?ClubSetting $model = null;

    public function model(): ClubSetting
    {
        return $this->model ??= ClubSetting::query()->whereKey(1)->firstOrFail();
    }

    public function refresh(): void
    {
        $this->model = null;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->model()->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    /** Trimmed string value, or '' when missing or not a string. */
    public function text(string $key): string
    {
        $value = $this->get($key);

        return is_string($value) ? trim($value) : '';
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function version(): int
    {
        return $this->model()->version;
    }

    public function fieldsVersion(): int
    {
        return $this->model()->fields_version;
    }

    /** Short name if set, otherwise the full club name. */
    public function displayName(): ?string
    {
        return ($this->text('short_name') ?: $this->text('name')) ?: null;
    }

    public function logoUrl(): ?string
    {
        return empty($this->get('logo_path')) ? null : route('branding.logo', ['v' => $this->version()]);
    }

    public function logoPath(): ?string
    {
        return $this->model()->logoPath();
    }

    public function logoDataUri(): ?string
    {
        return $this->model()->logoDataUri();
    }

    public function sepaReady(): bool
    {
        return $this->missingSepaFields() === [];
    }

    /** @return list<string> labels of the missing SEPA creditor fields */
    public function missingSepaFields(): array
    {
        return array_values(array_filter(
            self::SEPA_FIELDS,
            fn (string $label, string $key): bool => $this->text($key) === '',
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function mandateReady(): bool
    {
        foreach (self::MANDATE_FIELDS as $key) {
            if ($this->text($key) === '') {
                return false;
            }
        }

        return true;
    }
}
