<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Ausschreibungsposition (Variante + Menge + Preis). */
#[ORM\Entity]
#[ApiResource]
class Position
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tender::class, inversedBy: 'positions')]
    public ?Tender $tender = null;

    #[ORM\ManyToOne(targetEntity: Variant::class)]
    public ?Variant $variant = null;

    #[ORM\Column(type: 'float', nullable: true)]
    public ?float $quantity = null;

    /** unit: piece|carton|pallet|kg */
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $unit = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    public ?string $price = null;

    #[ORM\Column(length: 3)]
    public string $currency = 'EUR';
}
