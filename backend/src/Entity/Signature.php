<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Wiederverwendbare HTML-Signatur (verwaltbar, einem Postfach als Standard zuordenbar). */
#[ORM\Entity]
class Signature
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    #[ORM\Column(type: 'text')]
    public string $html = '';

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
