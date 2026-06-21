<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Hochgeladenes Dokument zu einer Firma (Datei liegt unter var/documents/, Text per Tika durchsuchbar). */
#[ORM\Entity]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Company $company = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    /** Dateityp/Label, z. B. PDF, XLSX. */
    #[ORM\Column(length: 20)]
    public string $type = 'PDF';

    /** Pfad relativ zu var/documents/, z. B. "12/0_angebot.pdf" (null = nur Metadaten). */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $path = null;

    #[ORM\Column(length: 150, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column]
    public int $size = 0;

    /** Extrahierter Text (für die Volltextsuche; per Tika gefüllt). */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $extractedText = null;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }
}
