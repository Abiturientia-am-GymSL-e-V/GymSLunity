<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_campaigns', function (Blueprint $table) {
            $table->longText('body')->change();
            $table->json('attachments')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('communication_campaigns', function (Blueprint $table) {
            $table->dropColumn('attachments');
            $table->text('body')->change();
        });
    }
};
