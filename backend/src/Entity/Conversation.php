<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** E-Mail-Konversation (Thread) in einem Postfach. */
#[ORM\Entity]
class Conversation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mailbox::class)]
    public ?Mailbox $mailbox = null;

    #[ORM\Column(length: 500)]
    public string $subject = '';

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $customerEmail = null;

    #[ORM\Column(length: 200, nullable: true)]
    public ?string $customerName = null;

    /** status: open|waiting|closed */
    #[ORM\Column(length: 20)]
    public string $status = 'open';

    /** Message-ID der ersten Nachricht (Threading-Anker). */
    #[ORM\Column(length: 998, nullable: true)]
    public ?string $rootMessageId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastMessageAt = null;

    /** @var Collection<int, Email> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Email::class)]
    #[ORM\OrderBy(['occurredAt' => 'ASC'])]
    public Collection $emails;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }
}
