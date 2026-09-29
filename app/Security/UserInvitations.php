<?php

declare(strict_types=1);

namespace App\Security;

use App\Configuration\MailConfigurator;
use App\Mail\UserInvitationMail;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use LogicException;
use Throwable;

/**
 * Mails new administration accounts a link to choose their own password.
 * Passwords are never sent by mail.
 */
final class UserInvitations
{
    public const BROKER = 'invitations';

    public function __construct(
        private readonly SecurityAudit $audit,
        private readonly MailConfigurator $mailConfigurator,
    ) {}

    public static function validHours(): int
    {
        return intdiv((int) config('auth.passwords.'.self::BROKER.'.expire', 4320), 60);
    }

    /** Sends a new link; an earlier link of the account stops working. */
    public function send(User $user, Request $request, bool $resent = false): bool
    {
        $token = $this->broker()->createToken($user);
        try {
            $this->mailConfigurator->applyStored();
            Mail::to($user->email)->send(new UserInvitationMail($user, $token, $resent));
        } catch (Throwable $exception) {
            report($exception);
            $this->revoke($user);
            $this->audit->record('user_invitation_sent', 'failed', $request, $request->user(), [], User::class, $user->getKey());

            return false;
        }
        $this->audit->record('user_invitation_sent', 'success', $request, $request->user(), ['resent' => $resent], User::class, $user->getKey());

        return true;
    }

    public function revoke(User $user): void
    {
        $this->broker()->deleteToken($user);
    }

    public function broker(): PasswordBroker
    {
        $broker = Password::broker(self::BROKER);
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('The invitation broker must store tokens in the database.');
        }

        return $broker;
    }
}
