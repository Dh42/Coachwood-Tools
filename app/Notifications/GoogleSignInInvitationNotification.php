<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The invitation email. Sign-in is Google only (decision 2026-11-27), so
 * this is the only kind: there's no password to set and no reset token.
 * Ported from the old app unchanged (its "Tutorial button" line was left
 * out in 3g and restored when tours were kept, decision 2026-11-29).
 */
class GoogleSignInInvitationNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The invitee's own domain, not the full allowed-domains config — a
        // Workspace org spanning several domains means the config value
        // could name a domain that isn't even this recipient's own.
        $domain = Str::after($notifiable->email, '@');

        return (new MailMessage)
            ->subject('You\'ve been invited to '.config('app.name'))
            ->line('An administrator has created a '.config('app.name').' account for you.')
            ->line("Sign in with your {$domain} Google account — no password needed.")
            ->action('Sign in with Google', route('login'))
            ->line('Once you\'re in, look for the "Tutorial" button at the top of each tool\'s page — it walks through what everything on that page does.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
