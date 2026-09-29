<?php

declare(strict_types=1);

namespace App\Members;

use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Mail\MemberWelcomeMail;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\Member;
use App\Models\User;
use App\PublicSite\PublicPageTemplates;
use App\SelfService\EmailAddressFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Welcome mails with sign-in instructions for the self-service portal.
 *
 * Every run is logged as a communication campaign of kind "welcome"; a member
 * has received the mail when one of its deliveries has the status "sent".
 * Deliveries are claimed under the member row lock, so parallel runs and
 * double submissions cannot mail the same member twice.
 */
final class WelcomeMails
{
    public const KIND = 'welcome';

    /** A pending delivery younger than this is treated as still being sent. */
    private const IN_FLIGHT_MINUTES = 15;

    public function __construct(
        private readonly ClubSettings $settings,
        private readonly EmailAddressFilter $emailFilter,
        private readonly MailConfigurator $mailConfigurator,
    ) {}

    /** The mail explains the portal, so it needs the portal to be enabled. */
    public function available(): bool
    {
        return $this->settings->enabled('selfservice_enabled');
    }

    public function automatic(): bool
    {
        return $this->available() && (bool) $this->settings->get('welcome_mail_automatic', true);
    }

    /** @param Builder<Member> $query */
    public static function whereReceived(Builder $query, bool $received): void
    {
        $sent = fn (QueryBuilder $deliveries) => $deliveries->selectRaw('1')
            ->from('communication_deliveries as welcome_deliveries')
            ->join('communication_campaigns as welcome_campaigns', 'welcome_campaigns.id', '=', 'welcome_deliveries.campaign_id')
            ->whereColumn('welcome_deliveries.member_id', 'members.id')
            ->where('welcome_deliveries.status', 'sent')
            ->where('welcome_campaigns.kind', self::KIND);
        $received ? $query->whereExists($sent) : $query->whereNotExists($sent);
    }

    /** @return array{sent_at: string, recipient_email: string|null}|null */
    public static function lastSent(Member $member): ?array
    {
        $delivery = self::deliveries($member->id)->where('status', 'sent')->latest('id')->first(['recipient_email', 'created_at']);

        return $delivery === null ? null : [
            'sent_at' => $delivery->created_at->toIso8601String(),
            'recipient_email' => $delivery->recipient_email,
        ];
    }

    /**
     * Sends to the given members; without $resend, members who already
     * received the mail are skipped.
     *
     * @param  list<int>  $memberNumbers
     * @return array{sent: int, failed: int, skipped: int, campaign: int}
     */
    public function send(array $memberNumbers, bool $resend, ?User $actor): array
    {
        $defaults = PublicPageTemplates::defaults();
        $ids = Member::query()->whereIn('member_number', $memberNumbers)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->pluck('id');
        $campaign = CommunicationCampaign::query()->create([
            'kind' => self::KIND,
            'format' => null,
            'subject' => (string) $this->settings->get('member_welcome_mail_subject', $defaults['member_welcome_mail_subject']),
            'body' => (string) $this->settings->get('member_welcome_mail_text', $defaults['member_welcome_mail_text']),
            'attachments' => [],
            'filters' => ['members' => $memberNumbers, 'resend' => $resend],
            'recipient_count' => 0,
            'created_by' => $actor?->id,
            'created_by_name' => $actor === null ? 'Automatischer Versand' : $actor->name,
            'created_at' => now(),
        ]);
        $this->mailConfigurator->applyStored();
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => count($memberNumbers) - $ids->count()];
        foreach ($ids as $id) {
            $counts[$this->deliver($campaign, $id, $resend)]++;
        }
        $campaign->update([
            'recipient_count' => $counts['sent'] + $counts['failed'],
            'success_count' => $counts['sent'],
            'failure_count' => $counts['failed'],
            'skipped_count' => $counts['skipped'],
        ]);

        return [...$counts, 'campaign' => $campaign->id];
    }

    /** Sends after the response when automatic delivery applies to the member. */
    public function sendAutomaticallyLater(Member $member): void
    {
        if (! $this->automatic() || ! $member->hasActiveOrUpcomingMembership() || self::skipReason($member) !== null) {
            return;
        }
        if (self::deliveries($member->id)->where('status', 'sent')->exists()) {
            return;
        }
        $memberNumber = (int) $member->member_number;
        defer(function () use ($memberNumber): void {
            try {
                $this->send([$memberNumber], false, null);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @return 'sent'|'failed'|'skipped' */
    private function deliver(CommunicationCampaign $campaign, int $memberId, bool $resend): string
    {
        $claim = DB::transaction(function () use ($campaign, $memberId, $resend): ?array {
            $member = Member::query()->whereKey($memberId)->lockForUpdate()->first();
            if ($member === null) {
                return null;
            }
            $reason = self::skipReason($member);
            $previous = self::deliveries($member->id);
            if ($reason === null && (clone $previous)->where('status', 'pending')->where('created_at', '>', now()->subMinutes(self::IN_FLIGHT_MINUTES))->exists()) {
                $reason = 'Versand läuft bereits.';
            }
            if ($reason === null && ! $resend && (clone $previous)->where('status', 'sent')->exists()) {
                $reason = 'Willkommensmail wurde bereits versendet.';
            }
            $delivery = $campaign->deliveries()->create([
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'recipient_name' => collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' '),
                'recipient_email' => $member->email,
                'status' => $reason === null ? 'pending' : 'skipped',
                'error' => $reason,
            ]);

            return $reason === null ? [$member, $delivery] : null;
        }, attempts: 3);
        if ($claim === null) {
            return 'skipped';
        }
        [$member, $delivery] = $claim;
        try {
            Mail::to((string) $member->email)->send(new MemberWelcomeMail($member, $this->emailFilter->requiresChange($member)));
        } catch (Throwable $exception) {
            report($exception);
            $delivery->update(['status' => 'failed', 'error' => 'Versand durch den Mailserver fehlgeschlagen.']);

            return 'failed';
        }
        $delivery->update(['status' => 'sent']);

        return 'sent';
    }

    private static function skipReason(Member $member): ?string
    {
        if ($member->deceased_at !== null) {
            return 'Mitglied ist verstorben.';
        }
        if (! is_string($member->email) || filter_var(trim($member->email), FILTER_VALIDATE_EMAIL) === false) {
            return 'Keine gültige E-Mail-Adresse hinterlegt.';
        }

        return null;
    }

    /** @return Builder<CommunicationDelivery> */
    private static function deliveries(int $memberId): Builder
    {
        return CommunicationDelivery::query()
            ->where('member_id', $memberId)
            ->whereHas('campaign', fn (Builder $campaign) => $campaign->where('kind', self::KIND));
    }
}
