<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Dokument zu einer Firma (Metadaten; Datei-Upload folgt später). */
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

    /** optionaler Pfad/URL (später: echter Upload). */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $path = null;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }
}
