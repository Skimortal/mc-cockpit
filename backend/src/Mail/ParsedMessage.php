<?php

namespace App\Mail;

/** Normalisierte Darstellung einer abgerufenen E-Mail (entkoppelt von webklex). */
final class ParsedMessage
{
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly ?string $fromAddress,
        public readonly ?string $fromName,
        public readonly ?string $toAddress,
        public readonly ?string $ccAddress,
        public readonly string $subject,
        public readonly ?string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly \DateTimeImmutable $date,
        /** @var list<array{name:string,mime:string,tmp:string,size:int}> */
        public readonly array $attachments = [],
    ) {
    }
}
