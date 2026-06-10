<?php

namespace App\Service\Llm;

use Anthropic\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claude-Implementierung über das offizielle anthropic-ai/sdk.
 * Nutzt Structured Outputs (json_schema) + Prompt-Caching auf dem System-Prompt.
 */
final class ClaudeClient implements LlmClient
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    private Client $client;

    public function __construct(
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] string $apiKey,
    ) {
        $this->client = new Client(apiKey: $apiKey);
    }

    public function extract(string $system, string $userText, array $schema, ?string $model = null, int $maxTokens = 1024): array
    {
        $message = $this->client->messages->create(
            model: $model ?: self::DEFAULT_MODEL,
            maxTokens: $maxTokens,
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => $userText],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        );

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
        $message = $this->client->messages->create(
            model: $model ?: self::DEFAULT_MODEL,
            maxTokens: $maxTokens,
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => $userText],
            ],
        );

        $out = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $out .= $block->text;
            }
        }

        return trim($out);
    }
}
