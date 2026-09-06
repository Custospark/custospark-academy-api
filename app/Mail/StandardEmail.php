<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Generic branded email using the Academy's standard blade template.
 * Mirrors the Custosell StandardEmail mailable so transaction emails
 * (receipts, notifications) share one view with PDF attachments.
 */
class StandardEmail extends Mailable
{
    /**
     * @param  array<int, array{data: string, name: string, mime: string}>  $fileAttachments
     */
    public function __construct(
        public readonly string  $title,
        public readonly string  $mailBody,
        public readonly ?string $ctaUrl          = null,
        public readonly ?string $ctaLabel        = null,
        public readonly ?string $tip             = null,
        public readonly ?string $logoUrl         = null,
        public readonly bool    $isHtml          = true,
        public readonly array   $fileAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.standard',
            with: [
                'title'    => $this->title,
                'mailBody' => $this->mailBody,
                'ctaUrl'   => $this->ctaUrl,
                'ctaLabel' => $this->ctaLabel,
                'tip'      => $this->tip,
                'logoUrl'  => $this->logoUrl,
                'isHtml'   => $this->isHtml,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(
                fn (): string => $file['data'],
                $file['name'],
            )->withMime($file['mime']),
            $this->fileAttachments,
        );
    }
}