<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** IBANs are stored encrypted with APP_KEY from now on. */
    public function up(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->text('iban')->nullable()->change());
        Schema::table('finance_mandates', fn (Blueprint $table) => $table->text('iban')->change());

        foreach (['members', 'finance_mandates'] as $table) {
            DB::table($table)->select(['id', 'iban'])->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $iban = trim((string) $row->iban);
                    DB::table($table)->where('id', $row->id)->update([
                        'iban' => $iban === '' ? ($table === 'members' ? null : '') : Crypt::encryptString($iban),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['members', 'finance_mandates'] as $table) {
            DB::table($table)->select(['id', 'iban'])->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    if (is_string($row->iban) && $row->iban !== '') {
                        DB::table($table)->where('id', $row->id)->update(['iban' => Crypt::decryptString($row->iban)]);
                    }
                }
            });
        }
        Schema::table('members', fn (Blueprint $table) => $table->string('iban', 34)->nullable()->change());
        Schema::table('finance_mandates', fn (Blueprint $table) => $table->string('iban', 42)->change());
    }
};
