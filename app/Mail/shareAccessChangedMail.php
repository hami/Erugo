<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Share;
use App\Models\Setting;

/**
 * Tells a recipient that a share's link and/or password has changed,
 * optionally including the new password.
 */
class shareAccessChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $share;
    public $recipient;
    public $recipient_name;
    public $sender_name;
    public $link_changed;
    public $password_changed;
    public $new_password;

    public function __construct(
        Share $share,
        $recipient,
        string $senderName,
        bool $linkChanged = false,
        bool $passwordChanged = false,
        ?string $newPassword = null
    ) {
        $this->share = $share;
        $this->recipient = $recipient;
        $this->recipient_name = explode(' ', $recipient['name'])[0];
        $this->sender_name = explode(' ', $senderName)[0];
        $this->link_changed = $linkChanged;
        $this->password_changed = $passwordChanged;
        $this->new_password = $newPassword;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Setting::where('key', 'email_subject_shareAccessChangedMail.twig')->first()?->value
                ?? 'Access to your files has changed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.shareAccessChangedMail',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
