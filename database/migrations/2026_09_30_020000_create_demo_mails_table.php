<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Outgoing mails of the public demo, shown in the demo mailbox instead of being delivered. */
    public function up(): void
    {
        Schema::create('demo_mails', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->default('');
            $table->string('sender')->default('');
            $table->json('recipients');
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->json('attachments');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_mails');
    }
};
