<?php

namespace App\Controller;

use App\Entity\Task;
use App\Mail\Mailer;
use App\Service\Llm\LlmClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReplyController extends AbstractController
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Mailer $mailer,
    ) {
    }

    #[Route('/api/tasks/{id}/draft-reply', methods: ['POST'])]
    public function draft(Task $task): JsonResponse
    {
        $system = <<<TXT
            Du schreibst im Namen der MOST Connect KG (Handelsvertretung) eine Antwort auf die unten
            stehende E-Mail-Konversation. Stil: professionell, freundlich, KNAPP, klar. Sprache: dieselbe
            wie die letzte Kundennachricht (i. d. R. Deutsch). Gib NUR den E-Mail-Text aus (keine
            Betreffzeile, keine Meta-Kommentare). Wenn der Name des Absenders bekannt ist, sprich ihn an.
            Schließe mit „Mit freundlichen Grüßen\nMOST Connect KG". Erfinde keine Fakten/Zusagen, die
            nicht aus dem Verlauf oder der Aufgabe hervorgehen.
            TXT;

        $draft = $this->llm->complete($system, $this->context($task));

        return $this->json(['draft' => $draft]);
    }

    #[Route('/api/tasks/{id}/reply', methods: ['POST'])]
    public function reply(Task $task, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $body = trim((string) ($data['body'] ?? ''));
        if ('' === $body) {
            return $this->json(['error' => 'Leerer Text.'], 400);
        }

        try {
            $email = $this->mailer->sendReply($task, $body, $data['subject'] ?? null);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'emailId' => $email->id, 'to' => $email->toAddress, 'subject' => $email->subject]);
    }

    private function context(Task $task): string
    {
        $lines = [];
        $lines[] = 'AUFGABE: '.$task->title;
        if ($task->aiSummary) {
            $lines[] = 'Worum es geht: '.$task->aiSummary;
        }
        $lines[] = '';
        $lines[] = '--- E-Mail-Verlauf (älteste zuerst) ---';

        $emails = $task->conversation?->emails ?? [];
        foreach ($emails as $e) {
            $who = 'out' === $e->direction ? 'WIR' : ($e->fromAddress ?? 'Kunde');
            $lines[] = sprintf('[%s, %s] %s', $who, $e->occurredAt->format('Y-m-d H:i'), $e->subject ?? '');
            $lines[] = mb_substr(trim((string) ($e->bodyText ?: strip_tags((string) $e->bodyHtml))), 0, 3000);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
