<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Einzelne Nachricht innerhalb einer Konversation (ein- oder ausgehend). */
#[ORM\Entity]
#[ORM\Index(columns: ['message_id'], name: 'idx_email_message_id')]
#[ApiResource]
class Email
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'emails')]
    public ?Conversation $conversation = null;

    /** direction: in|out */
    #[ORM\Column(length: 4)]
    public string $direction = 'in';

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $fromAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $toAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $bodyText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $bodyHtml = null;

    /** RFC-Message-ID (Idempotenz + Threading). */
    #[ORM\Column(length: 998, nullable: true)]
    public ?string $messageId = null;

    #[ORM\Column(length: 998, nullable: true)]
    public ?string $inReplyTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $refs = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $occurredAt;

    /** Zeitpunkt der KI-Triage (null = noch nicht verarbeitet). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $triagedAt = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }
}
