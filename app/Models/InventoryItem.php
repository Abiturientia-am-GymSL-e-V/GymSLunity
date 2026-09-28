<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $inventory_number
 * @property string $name
 * @property string $category
 * @property string|null $description
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string $location
 * @property string|null $responsible_person
 * @property string $acquisition_type
 * @property CarbonImmutable $acquisition_date
 * @property int $acquisition_cost_cents
 * @property string|null $document_reference
 * @property string $depreciation_method
 * @property int|null $useful_life_years
 * @property string $status
 * @property CarbonImmutable|null $disposed_at
 * @property int|null $disposal_proceeds_cents
 * @property string|null $disposal_note
 * @property int|null $created_by
 * @property string $created_by_name
 * @property int|null $disposed_by
 * @property string|null $disposed_by_name
 */
class InventoryItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'immutable_date:Y-m-d',
            'acquisition_cost_cents' => 'integer',
            'useful_life_years' => 'integer',
            'disposed_at' => 'immutable_date:Y-m-d',
            'disposal_proceeds_cents' => 'integer',
        ];
    }

    public function bookValueCents(?CarbonInterface $asOf = null): int
    {
        $effectiveDate = CarbonImmutable::instance($asOf ?? now())->startOfDay();
        if ($this->disposed_at && $effectiveDate->greaterThan($this->disposed_at)) {
            $effectiveDate = $this->disposed_at;
        }
        if ($effectiveDate->lessThan($this->acquisition_date)) {
            return $this->acquisition_cost_cents;
        }
        if ($this->depreciation_method === 'none') {
            return $this->acquisition_cost_cents;
        }
        if ($this->depreciation_method === 'immediate') {
            return 0;
        }

        $totalMonths = ((int) $this->useful_life_years) * 12;
        if ($totalMonths <= 0) {
            return $this->acquisition_cost_cents;
        }

        $elapsedMonths = (($effectiveDate->year - $this->acquisition_date->year) * 12)
            + $effectiveDate->month - $this->acquisition_date->month + 1;
        $elapsedMonths = min(max($elapsedMonths, 0), $totalMonths);
        $depreciated = intdiv(($this->acquisition_cost_cents * $elapsedMonths) + intdiv($totalMonths, 2), $totalMonths);

        return max(0, $this->acquisition_cost_cents - $depreciated);
    }

    public function annualDepreciationCents(): int
    {
        if ($this->depreciation_method !== 'linear' || ! $this->useful_life_years) {
            return $this->depreciation_method === 'immediate' ? $this->acquisition_cost_cents : 0;
        }

        return intdiv($this->acquisition_cost_cents + intdiv($this->useful_life_years, 2), $this->useful_life_years);
    }
}
