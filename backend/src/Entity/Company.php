<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Firma: Hersteller, Handelskette, Labor, Recycling, Spedition, EU-Vertretung … */
#[ORM\Entity]
#[ApiResource]
class Company
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    /** @var list<string> roles: manufacturer|retailer|lab|recycling|forwarder|eu_representation|other */
    #[ORM\Column(type: 'json')]
    public array $roles = [];

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
