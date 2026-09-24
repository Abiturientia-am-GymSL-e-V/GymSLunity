<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecurityPrune extends Command
{
    protected $signature = 'security:prune {--dry-run : Nur die Anzahl betroffener Datensätze anzeigen}';

    protected $description = 'Setzt das technische Aufbewahrungskonzept für Sicherheitsdaten um';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tasks = [];
        if (Schema::hasTable('selfservice_tokens')) {
            $tasks['Abgelaufene Selfservice-Tokens'] = DB::table('selfservice_tokens')->where('expires_at', '<', now());
        }
        if (Schema::hasTable('password_reset_tokens')) {
            $tasks['Abgelaufene Passwort-Reset-Tokens'] = DB::table('password_reset_tokens')->where('created_at', '<', now()->subMinutes((int) config('auth.passwords.users.expire', 60)));
        }
        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            $tasks['Inaktive Sitzungen'] = DB::table(config('session.table', 'sessions'))->where('last_activity', '<', now()->subSeconds((int) config('security.inactivity_timeout'))->timestamp);
        }

        foreach ($tasks as $label => $query) {
            $this->prune($label, $query, $dryRun);
        }
        if (Schema::hasTable('security_audit_events')) {
            $identifiers = DB::table('security_audit_events')
                ->where('created_at', '<', now()->subDays((int) config('security.audit_identifier_retention_days')))
                ->where(fn (Builder $query) => $query->whereNotNull('ip_hash')->orWhereNotNull('user_agent_hash'));
            $count = $identifiers->count();
            if (! $dryRun) {
                $identifiers->update(['ip_hash' => null, 'user_agent_hash' => null]);
            }
            $this->line("Pseudonyme Audit-Kennungen: {$count}".($dryRun ? ' (Simulation)' : ' bereinigt'));
            $this->prune('Abgelaufene Audit-Ereignisse', DB::table('security_audit_events')->where('created_at', '<', now()->subDays((int) config('security.audit_retention_days'))), $dryRun);
        }

        return self::SUCCESS;
    }

    private function prune(string $label, Builder $query, bool $dryRun): void
    {
        $count = $query->count();
        if (! $dryRun) {
            $query->delete();
        }
        $this->line("{$label}: {$count}".($dryRun ? ' (Simulation)' : ' gelöscht'));
    }
}
