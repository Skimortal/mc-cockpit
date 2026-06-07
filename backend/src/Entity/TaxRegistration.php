<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/** Steuer-/Zollregistrierung (AT/DE/UK + EORI). */
#[ORM\Entity]
#[ApiResource]
class TaxRegistration
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = '';

    /** taxGroup: at_hofer|de|gb_uk */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $taxGroup = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $taxNumber = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $eori = null;

    /** @var list<string> abgedeckte Länder */
    #[ORM\Column(type: 'json')]
    public array $coveredCountries = [];
}
