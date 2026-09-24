<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->default('environment');
            $table->string('from_address');
            $table->string('from_name');
            $table->string('reply_to_address')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_security', 20)->default('auto');
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->unsignedSmallInteger('smtp_timeout')->default(15);
            $table->string('smtp_local_domain')->nullable();
            $table->string('sendmail_path')->default('/usr/sbin/sendmail -bs -i');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::table('mail_settings')->insert([
            'id' => 1,
            'driver' => 'environment',
            'from_address' => (string) config('mail.from.address', 'hello@example.com'),
            'from_name' => (string) config('mail.from.name', config('app.name')),
            'smtp_security' => 'auto',
            'smtp_timeout' => 15,
            'sendmail_path' => '/usr/sbin/sendmail -bs -i',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
