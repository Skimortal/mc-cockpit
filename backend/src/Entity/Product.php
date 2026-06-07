<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    public ?Project $project = null;

    /** category: food|textile|other */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $category = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    /** @var Collection<int, Variant> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: Variant::class)]
    public Collection $variants;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
    }
}
