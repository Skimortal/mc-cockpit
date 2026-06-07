<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Mandat je Hersteller (z. B. „Mladegs EU"). */
#[ORM\Entity]
#[ApiResource]
class Project
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $manufacturer = null;

    /** category: food|textile|other */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $category = null;

    /** status: active|paused|ended */
    #[ORM\Column(length: 20)]
    public string $status = 'active';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;
}
