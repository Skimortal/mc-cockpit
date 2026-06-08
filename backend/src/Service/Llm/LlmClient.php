<?php

namespace App\Service\Llm;

/** Abstraktion über den LLM-Anbieter (Claude jetzt, OpenAI austauschbar). */
interface LlmClient
{
    /**
     * Strukturierte Extraktion: liefert ein Array gemäß $schema (JSON Schema).
     *
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    public function extract(string $system, string $userText, array $schema, ?string $model = null, int $maxTokens = 1024): array;
}
