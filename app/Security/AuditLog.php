<?php

declare(strict_types=1);

namespace App\Security;

use App\Members\MemberFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One chronological view over the separate protocols: security events,
 * member changes, configuration changes and the donation audit trail.
 */
final class AuditLog
{
    public const AREAS = [
        'security' => 'Sicherheit',
        'member' => 'Mitglieder',
        'configuration' => 'Konfiguration',
        'donation' => 'Spenden',
    ];

    private const ACTIONS = [
        'login' => 'Anmeldung',
        'logout' => 'Abmeldung',
        'passkey_registered' => 'Passkey registriert',
        'passkey_verified' => 'Passkey verwendet',
        'passkey_deleted' => 'Passkey gelöscht',
        'selfservice_passkey_registered' => 'Portal-Passkey registriert',
        'selfservice_passkey_login' => 'Portal-Anmeldung mit Passkey',
        'selfservice_passkey_deleted' => 'Portal-Passkey gelöscht',
        'roles_changed' => 'Rollen geändert',
        'user_invitation_sent' => 'Zugangsmail für Benutzerkonto versendet',
        'data_export' => 'Datenexport',
        'document_access' => 'Dokument abgerufen',
        'session_revoked' => 'Sitzung beendet',
        'member_viewed' => 'Mitglied angesehen',
        'configuration_backup_export' => 'Konfigurationssicherung heruntergeladen',
        'configuration_backup_restore' => 'Konfigurationssicherung eingespielt',
        'database_backup_export' => 'Datenbanksicherung heruntergeladen',
        'database_backup_restore' => 'Datenbanksicherung eingespielt',
        'member_changed' => 'Mitgliedsdaten geändert',
        'configuration_changed' => 'Konfiguration geändert',
    ];

    private const SUBJECTS = [
        'Member' => 'Mitglied', 'User' => 'Benutzerkonto', 'FinanceInvoice' => 'Rechnung', 'FinanceMandate' => 'SEPA-Mandat',
        'Receipt' => 'Quittung', 'Donation' => 'Spende', 'InventoryItem' => 'Inventar', 'CommunicationCampaign' => 'Kommunikation',
        'ContributionTransaction' => 'Kontobuchung', 'Contribution' => 'Beitrag',
    ];

    private const OUTCOMES = ['success' => 'Erfolgreich', 'failed' => 'Fehlgeschlagen', 'rate_limited' => 'Gedrosselt'];

    /** @param array<string, string> $filters search, from, to, area */
    public function query(array $filters): Builder
    {
        $security = DB::table('security_audit_events')->select([
            DB::raw("'security' as area"), 'id as source_id', 'created_at as occurred_at', 'event as action',
            'outcome', 'actor_name', 'subject_type', 'subject_id', 'context as details',
        ]);
        $members = DB::table('member_changes')
            ->leftJoin('members', 'members.id', '=', 'member_changes.member_id')
            ->select([
                DB::raw("'member' as area"), 'member_changes.id as source_id', 'member_changes.created_at as occurred_at',
                DB::raw("'member_changed' as action"), DB::raw("'success' as outcome"), 'member_changes.actor_name',
                DB::raw("'Mitglied' as subject_type"), 'members.member_number as subject_id', 'member_changes.changed_fields as details',
            ]);
        $configuration = DB::table('configuration_changes')->select([
            DB::raw("'configuration' as area"), 'id as source_id', 'created_at as occurred_at',
            DB::raw("'configuration_changed' as action"), DB::raw("'success' as outcome"), 'actor_name',
            DB::raw("'Konfiguration' as subject_type"), 'subject as subject_id', DB::raw('NULL as details'),
        ]);
        $donations = DB::table('donation_audits')->select([
            DB::raw("'donation' as area"), 'id as source_id', 'created_at as occurred_at', 'event as action',
            DB::raw("'success' as outcome"), 'actor_name', DB::raw("'Spende' as subject_type"), 'donation_id as subject_id',
            DB::raw('NULL as details'),
        ]);

        $query = DB::query()->fromSub($security->unionAll($members)->unionAll($configuration)->unionAll($donations), 'audit');
        if (($filters['area'] ?? 'all') !== 'all') {
            $query->where('area', $filters['area']);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            // Search the German labels as well as the stored keys.
            $actions = array_keys(array_filter(self::ACTIONS, fn (string $label): bool => mb_stripos($label, $search) !== false));
            $query->where(function (Builder $match) use ($pattern, $actions): void {
                foreach (['actor_name', 'action', 'subject_id'] as $column) {
                    $match->orWhereRaw("$column LIKE ? ESCAPE '!'", [$pattern]);
                }
                if ($actions !== []) {
                    $match->orWhereIn('action', $actions);
                }
            });
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('source_id');
    }

    /**
     * A row as shown and exported.
     *
     * @return array{id: string, occurred_at: string, area: string, action: string, outcome: string, actor: string, subject: string, details: string}
     */
    public function present(stdClass $row): array
    {
        $area = (string) $row->area;

        return [
            'id' => $area.'-'.$row->source_id,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->setTimezone((string) config('app.display_timezone'))->format('Y-m-d H:i:s'),
            'area' => self::AREAS[$area] ?? $area,
            'action' => self::ACTIONS[(string) $row->action] ?? ($area === 'donation' ? 'Spende: '.$row->action : (string) $row->action),
            'outcome' => self::OUTCOMES[(string) $row->outcome] ?? (string) $row->outcome,
            'actor' => is_string($row->actor_name) && $row->actor_name !== '' ? $row->actor_name : 'System',
            'subject' => trim((self::SUBJECTS[(string) $row->subject_type] ?? $row->subject_type ?? '').' '.($row->subject_id ?? '')),
            'details' => $this->details($area, $row->details),
        ];
    }

    private function details(string $area, mixed $raw): string
    {
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || $data === []) {
            return '';
        }
        if ($area === 'member') {
            $labels = array_column(MemberFields::directoryFields(), 'label', 'key');

            return 'Geändert: '.implode(', ', array_map(fn (mixed $key): string => $labels[(string) $key] ?? (string) $key, $data));
        }

        return implode(', ', array_map(
            fn (string $key, mixed $value): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)),
            array_keys($data),
            $data,
        ));
    }
}
