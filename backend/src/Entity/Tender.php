<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Ausschreibung / Deal (länderübergreifend; gewonnene Ländergruppen). */
#[ORM\Entity]
#[ApiResource]
class Tender
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    /** Abnehmer (Handelskette, z. B. Aldi). */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $account = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    public ?Project $project = null;

    /** @var list<string> umfasste Länder: DE,AT,CH,IT,SI,HU,GB,IE */
    #[ORM\Column(type: 'json')]
    public array $countries = [];

    /** @var list<string> gewonnene Gruppen: DE, Hofer-Länder, GB+IE */
    #[ORM\Column(type: 'json')]
    public array $wonCountryGroups = [];

    /** channel: ariba|excel|email|portal|other */
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $channel = null;

    /** incoterm: EXW|FCA|FOB|CFR|CIF|DAP|DPU|DDP|other */
    #[ORM\Column(length: 10, nullable: true)]
    public ?string $incoterm = null;

    /** stage: lead|first_contact|sample_sent|submitted|awarded|lost */
    #[ORM\Column(length: 20)]
    public string $stage = 'lead';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    public ?string $value = null;

    #[ORM\Column(length: 3)]
    public string $currency = 'EUR';

    /** @var Collection<int, Position> */
    #[ORM\OneToMany(mappedBy: 'tender', targetEntity: Position::class)]
    public Collection $positions;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }
}
