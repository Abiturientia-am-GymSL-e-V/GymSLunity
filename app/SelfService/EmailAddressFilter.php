<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Models\Member;
use Illuminate\Validation\ValidationException;

/**
 * Allow- or blocklist for addresses members enter themselves.
 *
 * Patterns are matched against the whole, lowercased address; `*` matches any
 *
 * run of characters except `@`, so `*@*.gymsl.de` covers subdomains but not
 * `gymsl.de` itself. The administration may bypass the filter; the self-service
 * portal then asks for a new address before anything else can be used.
 */
final class EmailAddressFilter
{
    public const MODES = ['off', 'allow', 'block'];

    public const MAX_PATTERNS = 500;

    private const MAX_PATTERN_LENGTH = 255;

    public function __construct(private readonly ClubSettings $settings) {}

    public function mode(): string
    {
        $mode = $this->settings->get('email_filter_mode', 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public function allows(string $email): bool
    {
        $mode = $this->mode();
        if ($mode === 'off') {
            return true;
        }
        $matches = self::matchesAny(self::normalizeEmail($email), self::parse((string) $this->settings->get('email_filter_patterns', '')));

        return $mode === 'allow' ? $matches : ! $matches;
    }

    public function requiresChange(Member $member): bool
    {
        $email = self::normalizeEmail((string) $member->email);

        return $email !== '' && ! $this->allows($email);
    }

    /** Throws a validation error on the given field when the address is filtered. */
    public function assertAllowed(string $email, string $field = 'email'): void
    {
        if (! $this->allows($email)) {
            throw ValidationException::withMessages([$field => 'Diese E-Mail-Adresse ist für den Mitgliederbereich nicht zugelassen. Bitte eine andere Adresse verwenden.']);
        }
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Normalized, de-duplicated patterns; blank lines are ignored.
     *
     * @return list<string>
     */
    public static function parse(string $lines): array
    {
        $patterns = array_map(fn (string $line): string => mb_strtolower(trim($line)), preg_split('/\R/u', $lines) ?: []);

        return array_values(array_unique(array_filter($patterns, fn (string $pattern): bool => $pattern !== '')));
    }

    /**
     * Messages for every invalid line, keyed by its 1-based line number.
     *
     * @return array<int, string>
     */
    public static function errors(string $lines): array
    {
        $errors = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $index => $line) {
            $pattern = mb_strtolower(trim($line));
            if ($pattern === '') {
                continue;
            }
            $error = self::patternError($pattern);
            if ($error !== null) {
                $errors[$index + 1] = 'Zeile '.($index + 1).' („'.mb_strimwidth($pattern, 0, 60, '…').'“): '.$error;
            }
        }

        return $errors;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(string $email, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::patternError($pattern) === null && preg_match(self::regex($pattern), $email) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function patternError(string $pattern): ?string
    {
        if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return 'Das Muster ist zu lang.';
        }
        if (preg_match('/\s/u', $pattern) === 1) {
            return 'Leerzeichen sind nicht erlaubt.';
        }
        if (substr_count($pattern, '@') !== 1) {
            return 'Das Muster muss genau ein @ enthalten.';
        }
        [$local, $domain] = explode('@', $pattern);
        if ($local === '' || $domain === '') {
            return 'Vor und nach dem @ muss etwas stehen, zum Beispiel * als Platzhalter.';
        }
        if (preg_match('/\A[\p{L}\p{N}*.-]+\z/u', $domain) !== 1) {
            return 'Die Domain darf nur Buchstaben, Ziffern, Punkte, Bindestriche und * enthalten.';
        }
        if (str_contains($domain, '..') || str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            return 'Die Domain enthält einen ungültigen Punkt.';
        }

        return null;
    }

    private static function regex(string $pattern): string
    {
        $parts = array_map(fn (string $part): string => preg_quote($part, '/'), explode('*', $pattern));

        return '/\A'.implode('[^@]*', $parts).'\z/u';
    }
}
