<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** In-App-Benachrichtigung für einen Benutzer (zugewiesen, Kommentar, fällig …). */
#[ORM\Entity]
#[ORM\Index(columns: ['user_id', 'is_read'])]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Empfänger. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    /** type: assigned|comment|due */
    #[ORM\Column(length: 30)]
    public string $type = 'info';

    #[ORM\Column(type: 'text')]
    public string $text = '';

    #[ORM\Column(nullable: true)]
    public ?int $taskId = null;

    #[ORM\Column(nullable: true)]
    public ?int $conversationId = null;

    #[ORM\Column]
    public bool $isRead = false;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
