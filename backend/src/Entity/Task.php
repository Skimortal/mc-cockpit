<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Aufgabe – häufig aus einer eingehenden E-Mail per KI-Triage erzeugt. */
#[ORM\Entity]
#[ApiResource]
class Task
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $title = '';

    /** type: general|send_sample|deadline|send_asn|label_manhattan|logistics|lab */
    #[ORM\Column(length: 30)]
    public string $type = 'general';

    /** status: open|in_progress|waiting|done */
    #[ORM\Column(length: 20)]
    public string $status = 'open';

    /** priority: low|normal|high */
    #[ORM\Column(length: 10)]
    public string $priority = 'normal';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $dueDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $assignee = null;

    #[ORM\ManyToOne(targetEntity: Tender::class)]
    public ?Tender $tender = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    public ?Conversation $conversation = null;

    /** Auslösende E-Mail (für „Antwort aus der Aufgabe"). */
    #[ORM\ManyToOne(targetEntity: Email::class)]
    public ?Email $sourceEmail = null;

    /** Von der KI vorgeschlagener Zuständiger (Freitext, bevor zugewiesen wird). */
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $suggestedAssignee = null;

    /** KI-Kurzzusammenfassung der Mail. */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $aiSummary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
