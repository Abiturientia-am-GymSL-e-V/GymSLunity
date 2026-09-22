<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('gender', 1)->nullable();
            $table->decimal('sponsor_contribution', 8, 2)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('mandate_reference')->nullable();
            $table->date('mandate_signed_at')->nullable();
            foreach (['account_holder_first_name', 'account_holder_last_name', 'account_holder_street', 'account_holder_city', 'account_holder_country'] as $column) {
                $table->string($column)->nullable();
            }
            $table->string('account_holder_postal_code', 20)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
        });

        Schema::create('member_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->unsignedInteger('version');
            $table->json('before');
            $table->json('after');
            $table->json('changed_fields');
            $table->timestamp('created_at');
            $table->unique(['member_id', 'version']);
        });

        // Document bytes never form part of a member update or an Inertia payload.
        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->boolean('submitted_online')->default(false);
            $table->binary('contents');
            $table->timestamps();
            $table->unique(['member_id', 'kind']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE member_documents MODIFY contents LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('member_changes');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn([
            'gender', 'sponsor_contribution', 'iban', 'mandate_reference', 'mandate_signed_at',
            'account_holder_first_name', 'account_holder_last_name', 'account_holder_street',
            'account_holder_postal_code', 'account_holder_city', 'account_holder_country', 'lock_version',
        ]));
    }
};
