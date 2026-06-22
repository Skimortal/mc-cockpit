<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ein KI-Aufruf: Tokens + geschätzte Kosten (für Monatsbudget/Reporting). */
#[ORM\Entity]
#[ORM\Index(name: 'IDX_LLMUSAGE_CREATED', columns: ['created_at'])]
class LlmUsage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 60)]
    public string $model = '';

    #[ORM\Column]
    public int $inputTokens = 0;

    #[ORM\Column]
    public int $outputTokens = 0;

    /** Geschätzte Kosten in Mikro-USD (1_000_000 = 1 USD). */
    #[ORM\Column]
    public int $costMicros = 0;

    #[ORM\Column(length: 40)]
    public string $feature = '';

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
