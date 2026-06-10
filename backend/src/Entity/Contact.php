<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
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

    /** Abteilung als Freitext (Direktor, Logistik, Finanzen, Einkauf, QM …). */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $department = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $company = null;

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $phone = null;
}
