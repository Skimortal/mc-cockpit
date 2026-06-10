<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Kommentar an einer Aufgabe (GitLab-Stil). */
#[ORM\Entity]
class TaskComment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Task $task = null;

    #[ORM\Column(length: 100)]
    public string $authorName = '';

    #[ORM\Column(type: 'text')]
    public string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
