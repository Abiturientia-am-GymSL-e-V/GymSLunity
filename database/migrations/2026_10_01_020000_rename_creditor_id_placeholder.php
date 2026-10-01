<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SEPA creditor ID now has one placeholder everywhere, the German
 * {{verein.glaeubiger_id}}. Saved form texts, legal pages and mail texts used
 * {{verein.creditor_id}}; they are rewritten so the configuration shows the
 * current name. Texts saved elsewhere, e.g. earlier serial mails reused as a
 * template, keep working through ClubData::upgradePlaceholders().
 *
 * Running it again changes nothing.
 */
return new class extends Migration
{
    private const OLD = '{{verein.creditor_id}}';

    private const NEW = '{{verein.glaeubiger_id}}';

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('club_settings')->lockForUpdate()->get(['id', 'data']) as $row) {
                $data = json_decode((string) $row->data, true);
                if (! is_array($data)) {
                    continue;
                }
                $updated = $this->rename($data);
                if ($updated !== $data) {
                    DB::table('club_settings')->where('id', $row->id)->update(['data' => json_encode($updated, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

    public function down(): void
    {
        // The old name stays accepted, so there is nothing to undo.
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function rename(array $data): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_string($value) => str_replace(self::OLD, self::NEW, $value),
            is_array($value) => $this->rename($value),
            default => $value,
        }, $data);
    }
};
