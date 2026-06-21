<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An eine Aufgabe angehängte Datei (z. B. ZIP), liegt unter var/task-files/. */
#[ORM\Entity]
class TaskFile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Task $task = null;

    #[ORM\Column(length: 255)]
    public string $filename = '';

    #[ORM\Column(length: 150, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column]
    public int $size = 0;

    /** Pfad relativ zu var/task-files/, z. B. "12/0_angebot.zip". */
    #[ORM\Column(length: 500)]
    public string $path = '';

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $uploadedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
