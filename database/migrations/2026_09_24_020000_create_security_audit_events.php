<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80)->index();
            $table->string('outcome', 20)->default('success')->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('member_documents', function (Blueprint $table): void {
            $table->boolean('encrypted')->default(false)->after('submitted_online');
            $table->char('content_sha256', 64)->nullable()->after('encrypted');
        });
        $this->encryptExistingDocuments();
    }

    public function down(): void
    {
        $this->decryptExistingDocuments();
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->dropColumn(['encrypted', 'content_sha256']);
        });
        Schema::dropIfExists('security_audit_events');
    }

    private function encryptExistingDocuments(): void
    {
        DB::table('member_documents')->where('encrypted', false)->orderBy('id')->chunkById(10, function ($documents): void {
            foreach ($documents as $document) {
                $contents = is_resource($document->contents) ? stream_get_contents($document->contents) : $document->contents;
                if (! is_string($contents)) {
                    throw new RuntimeException('Ein vorhandenes Mitgliedsdokument konnte nicht verschlüsselt werden.');
                }
                DB::table('member_documents')->where('id', $document->id)->update([
                    'contents' => Crypt::encryptString($contents),
                    'encrypted' => true,
                    'content_sha256' => hash('sha256', $contents),
                ]);
            }
        });
    }

    private function decryptExistingDocuments(): void
    {
        DB::table('member_documents')->where('encrypted', true)->orderBy('id')->chunkById(10, function ($documents): void {
            foreach ($documents as $document) {
                $stored = is_resource($document->contents) ? stream_get_contents($document->contents) : $document->contents;
                if (! is_string($stored)) {
                    throw new RuntimeException('Ein verschlüsseltes Mitgliedsdokument konnte nicht gelesen werden.');
                }
                DB::table('member_documents')->where('id', $document->id)->update(['contents' => Crypt::decryptString($stored)]);
            }
        });
    }
};
