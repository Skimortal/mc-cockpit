<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\TaskFile;
use App\Mail\Mailer;
use App\Service\Llm\LlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReplyController extends AbstractController
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Mailer $mailer,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
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

    /** Vorbelegung fürs Antwort-Fenster: Empfänger (allen antworten), Betreff, Signatur. */
    #[Route('/api/tasks/{id}/reply-context', methods: ['GET'])]
    public function replyContext(Task $task): JsonResponse
    {
        $conv = $task->conversation;
        $mailbox = $conv?->mailbox;
        $own = $mailbox ? mb_strtolower($mailbox->email) : '';

        // letzte eingehende Nachricht als Basis für „Allen antworten"
        $lastIn = null;
        foreach ($conv?->emails ?? [] as $e) {
            if ('in' === $e->direction) {
                $lastIn = $e;
            }
        }
        $src = $lastIn ?? $task->sourceEmail;

        $to = $src?->fromAddress ? [$src->fromAddress] : ($conv?->customerEmail ? [$conv->customerEmail] : []);
        $cc = [];
        foreach (preg_split('/[,;]+/', (string) ($src?->ccAddress ?? '')) ?: [] as $a) {
            $a = trim($a);
            if ('' !== $a && str_contains($a, '@')) {
                $cc[] = $a;
            }
        }
        // eigene Adresse + bereits in To enthaltene rausfiltern
        $norm = fn (array $xs) => array_values(array_unique(array_filter($xs, fn ($x) => mb_strtolower($x) !== $own)));
        $to = $norm($to);
        $cc = array_values(array_filter($norm($cc), fn ($x) => !\in_array(mb_strtolower($x), array_map('mb_strtolower', $to), true)));

        $subject = $src?->subject ?: $conv?->subject;
        $subject = preg_match('/^\s*(re|aw)\s*:/iu', (string) $subject) ? $subject : 'Re: '.$subject;

        $signatures = array_map(
            fn (\App\Entity\Signature $s) => ['id' => $s->id, 'name' => $s->name, 'html' => $s->html],
            $this->em->getRepository(\App\Entity\Signature::class)->findBy([], ['name' => 'ASC'])
        );

        return $this->json([
            'to' => implode(', ', $to),
            'cc' => implode(', ', $cc),
            'subject' => $subject,
            'signatures' => $signatures,
            'defaultSignatureId' => $mailbox?->defaultSignature?->id,
            'signature' => $mailbox?->defaultSignature?->html ?? '',
            'fromName' => $mailbox?->name ?? '',
            'fromEmail' => $mailbox?->email ?? '',
        ]);
    }

    #[Route('/api/tasks/{id}/reply', methods: ['POST'])]
    public function reply(Task $task, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $body = trim((string) ($data['body'] ?? ''));
        if ('' === $body) {
            return $this->json(['error' => 'Leerer Text.'], 400);
        }

        // ausgewählte Aufgaben-Dateien als Anhänge auflösen
        $attachments = [];
        foreach ((array) ($data['fileIds'] ?? []) as $fid) {
            $f = $this->em->getRepository(TaskFile::class)->find((int) $fid);
            if ($f && $f->task?->id === $task->id && '' !== $f->path) {
                $attachments[] = [
                    'path' => $this->projectDir.'/var/task-files/'.$f->path,
                    'name' => $f->filename,
                    'type' => $f->contentType,
                ];
            }
        }

        try {
            $email = $this->mailer->sendReply(
                $task,
                $body,
                $data['subject'] ?? null,
                isset($data['to']) ? (string) $data['to'] : null,
                isset($data['cc']) ? (string) $data['cc'] : null,
                isset($data['signature']) ? (string) $data['signature'] : null,
                $attachments,
            );
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
