<?php

namespace App\Service\Llm;

use Anthropic\Client;
use App\Entity\LlmUsage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claude-Implementierung über das offizielle anthropic-ai/sdk.
 * Erfasst Token-Verbrauch/Kosten und stoppt bei überschrittenem Monatsbudget.
 */
final class ClaudeClient implements LlmClient
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';
    /** USD je 1 Mio. Tokens [input, output]. */
    private const PRICES = [
        'opus' => [5.0, 25.0],
        'haiku' => [1.0, 5.0],
        'sonnet' => [3.0, 15.0],
    ];

    private Client $client;

    public function __construct(
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] string $apiKey,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(float:LLM_MONTHLY_BUDGET)%')] private readonly float $monthlyBudgetUsd = 0,
    ) {
        $this->client = new Client(apiKey: $apiKey);
    }

    public function extract(string $system, string $userText, array $schema, ?string $model = null, int $maxTokens = 1024): array
    {
        $this->assertBudget();
        $model = $model ?: self::DEFAULT_MODEL;
        $message = $this->client->messages->create(
            model: $model,
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $userText]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        );
        $this->record($model, $message, 'extract');

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $data = json_decode($block->text, true);
                if (\is_array($data)) {
                    return $data;
                }
            }
        }

        return [];
    }

    public function complete(string $system, string $userText, ?string $model = null, int $maxTokens = 1500): string
    {
        $this->assertBudget();
        $model = $model ?: self::DEFAULT_MODEL;
        $message = $this->client->messages->create(
            model: $model,
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $userText]],
        );
        $this->record($model, $message, 'complete');

        $out = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $out .= $block->text;
            }
        }

        return trim($out);
    }

    /** Wirft, wenn das Monatsbudget erreicht ist (0 = unbegrenzt). */
    private function assertBudget(): void
    {
        if ($this->monthlyBudgetUsd <= 0) {
            return;
        }
        if ($this->monthSpentMicros() >= (int) round($this->monthlyBudgetUsd * 1_000_000)) {
            throw new \RuntimeException('KI-Monatsbudget erreicht – KI-Funktionen pausiert (Limit: LLM_MONTHLY_BUDGET).');
        }
    }

    public function monthSpentMicros(): int
    {
        $start = new \DateTimeImmutable('first day of this month midnight');

        return (int) $this->em->createQuery(
            'SELECT COALESCE(SUM(u.costMicros), 0) FROM App\Entity\LlmUsage u WHERE u.createdAt >= :m'
        )->setParameter('m', $start)->getSingleScalarResult();
    }

    private function record(string $model, object $message, string $feature): void
    {
        try {
            $u = $message->usage ?? null;
            $in = $this->tok($u, ['inputTokens', 'input_tokens']);
            $out = $this->tok($u, ['outputTokens', 'output_tokens']);
            [$inRate, $outRate] = self::PRICES[$this->tier($model)];
            $usage = new LlmUsage();
            $usage->model = mb_substr($model, 0, 60);
            $usage->inputTokens = $in;
            $usage->outputTokens = $out;
            $usage->costMicros = (int) round($in * $inRate + $out * $outRate);
            $usage->feature = $feature;
            $this->em->persist($usage);
            $this->em->flush();
        } catch (\Throwable) {
            // Erfassung darf den KI-Aufruf nie scheitern lassen.
        }
    }

    private function tier(string $model): string
    {
        $m = mb_strtolower($model);
        if (str_contains($m, 'opus')) {
            return 'opus';
        }
        if (str_contains($m, 'haiku')) {
            return 'haiku';
        }

        return 'sonnet';
    }

    /** @param string[] $names */
    private function tok(?object $u, array $names): int
    {
        foreach ($names as $n) {
            if ($u && property_exists($u, $n)) {
                return (int) $u->$n;
            }
        }

        return 0;
    }
}
