<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class Contact
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $firstName = '';

    #[ORM\Column(length: 100)]
    public string $lastName = '';

    /** function: management|purchasing|lab_quality|logistics|accounting|other */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $function = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $company = null;

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $phone = null;
}
