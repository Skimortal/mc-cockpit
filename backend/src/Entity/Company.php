<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Firma: Hersteller, Handelskette, Labor, Recycling, Spedition, EU-Vertretung … */
#[ORM\Entity]
class Company
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    /** kind: hersteller (unser Kunde) | handel (Abnehmer) | other */
    #[ORM\Column(length: 20, options: ['default' => 'hersteller'])]
    public string $kind = 'hersteller';

    /** kurze Unterzeile, z. B. „vertreten durch Mladegs Austria GmbH". */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $subtitle = null;

    /** @var list<string> roles: manufacturer|retailer|lab|recycling|forwarder|eu_representation|other */
    #[ORM\Column(type: 'json')]
    public array $roles = [];

    /** @var list<string> freie Tags */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    public array $tags = [];

    /** @var list<array{label:string,value:string}> frei erweiterbare Stammdaten-Felder */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    public array $customFields = [];

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $note = null;

    /** „vertreten durch" (z. B. Mladegs Pak -> Mladegs Austria) */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'represents')]
    public ?Company $representedBy = null;

    /** @var Collection<int, Company> */
    #[ORM\OneToMany(mappedBy: 'representedBy', targetEntity: self::class)]
    public Collection $represents;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    public function __construct()
    {
        $this->represents = new ArrayCollection();
    }
}
