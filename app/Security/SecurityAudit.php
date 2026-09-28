<?php

declare(strict_types=1);

namespace App\Security;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SecurityAudit
{
    /** @param array<string, mixed> $context */
    public function record(
        string $event,
        string $outcome = 'success',
        ?Request $request = null,
        ?User $user = null,
        array $context = [],
        ?string $subjectType = null,
        string|int|null $subjectId = null,
    ): void {
        try {
            if (! Schema::hasTable('security_audit_events')) {
                return;
            }

            SecurityAuditEvent::query()->create([
                'user_id' => $user?->getKey() ?? $request?->user()?->getKey(),
                'event' => substr($event, 0, 80),
                'outcome' => substr($outcome, 0, 20),
                'subject_type' => $subjectType ? substr($subjectType, 0, 80) : null,
                'subject_id' => $subjectId === null ? null : substr((string) $subjectId, 0, 120),
                'ip_hash' => $this->fingerprint($request?->ip()),
                'user_agent_hash' => $this->fingerprint($request?->userAgent()),
                'context' => $this->sanitize($context),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // An unavailable audit sink must not reveal internals to a user or
            // prevent login, while the operational log still records the fault.
            Log::error('Security audit event could not be persisted.', [
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }
    }

    public function fingerprint(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', mb_strtolower(trim($value)), (string) config('app.key'));
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $blocked = ['password', 'token', 'secret', 'recovery', 'contents', 'authorization', 'cookie'];
        $clean = [];
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (collect($blocked)->contains(fn (string $term): bool => str_contains($normalizedKey, $term))) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return $clean;
    }
}
