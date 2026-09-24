<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\SecurityAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecurityAuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $events = SecurityAuditEvent::query()->select('event')->distinct()->orderBy('event')->pluck('event')->all();
        $filters = $request->validate([
            'event' => ['nullable', 'string', Rule::in($events)],
            'outcome' => ['nullable', Rule::in(['success', 'failed', 'rate_limited'])],
        ]);
        $query = SecurityAuditEvent::query()
            ->leftJoin('users', 'users.id', '=', 'security_audit_events.user_id')
            ->select('security_audit_events.id', 'security_audit_events.event', 'security_audit_events.outcome', 'security_audit_events.subject_type', 'security_audit_events.subject_id', 'security_audit_events.context', 'security_audit_events.created_at', 'users.name as actor_name');
        if (! empty($filters['event'])) {
            $query->where('security_audit_events.event', $filters['event']);
        }
        if (! empty($filters['outcome'])) {
            $query->where('security_audit_events.outcome', $filters['outcome']);
        }

        return Inertia::render('configuration/SecurityAudit', [
            'entries' => $query->latest('security_audit_events.id')->paginate(50)->withQueryString(),
            'events' => $events,
            'filters' => ['event' => $filters['event'] ?? '', 'outcome' => $filters['outcome'] ?? ''],
            'retentionDays' => config('security.audit_retention_days'),
            'identifierRetentionDays' => config('security.audit_identifier_retention_days'),
        ]);
    }
}
