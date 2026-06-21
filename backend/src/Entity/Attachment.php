<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Datei-Anhang einer E-Mail (auf der Platte gespeichert; Pfad relativ zu var/attachments). */
#[ORM\Entity]
class Attachment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Email::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Email $email = null;

    #[ORM\Column(length: 255)]
    public string $filename = '';

    #[ORM\Column(length: 150, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column]
    public int $size = 0;

    /** Pfad relativ zu var/attachments/, z. B. "123/0_angebot.pdf". */
    #[ORM\Column(length: 500)]
    public string $path = '';

    /** Extrahierter Text (für die Volltextsuche; wird in Schritt 2 per Tika gefüllt). */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $extractedText = null;

    /** Gesetzt, wenn die Datei per Retention von der Platte entfernt wurde (Text/Metadaten bleiben). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $prunedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
