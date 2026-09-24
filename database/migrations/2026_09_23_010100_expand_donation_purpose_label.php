<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->text('purpose_label')->change();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('purpose_label')->change();
        });
    }
};
