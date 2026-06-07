<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Produktvariante (Gebinde), z. B. „Ketchup 1 kg". */
#[ORM\Entity]
#[ApiResource]
class Variant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    public ?Product $product = null;

    /** Gebinde/Größe, z. B. „1 kg" */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $packaging = null;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $ean = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $articleNumber = null;

    #[ORM\Column(type: 'float', nullable: true)]
    public ?float $netWeight = null;
}
