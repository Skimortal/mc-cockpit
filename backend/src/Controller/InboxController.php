<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Task;
use App\Service\Triage\EmailTriageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Posteingang (Konversationen) + „Mail → Aufgabe". */
class InboxController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailTriageService $triage,
    ) {
    }

    #[Route('/api/inbox', methods: ['GET'])]
    public function inbox(): JsonResponse
    {
        $byConv = $this->tasksByConversation();
        $convs = $this->em->getRepository(Conversation::class)->findBy([], ['createdAt' => 'DESC'], 300);

        $out = [];
        foreach ($convs as $c) {
            $task = $byConv[$c->id] ?? null;
            $state = $task ? ('done' === $task->status ? 'erledigt' : 'aufgabe') : 'neu';
            $out[] = [
                'id' => $c->id,
                'from' => $c->customerName ?: $c->customerEmail,
                'email' => $c->customerEmail,
                'subject' => $c->subject,
                'lastMessageAt' => ($c->lastMessageAt ?? $c->createdAt)->format('Y-m-d H:i'),
                'messageCount' => $c->emails->count(),
                'state' => $state,
                'taskId' => $task?->id,
                'owner' => $task?->assignee ? trim($task->assignee->getFirstName().' '.$task->assignee->getLastName()) : null,
            ];
        }

        return $this->json($out);
    }

    #[Route('/api/conversations/{id}', methods: ['GET'])]
    public function conversation(Conversation $c): JsonResponse
    {
        $task = $this->tasksByConversation()[$c->id] ?? null;
        $messages = [];
        foreach ($c->emails as $e) {
            $messages[] = [
                'dir' => $e->direction,
                'who' => 'out' === $e->direction ? 'Wir' : ($e->fromAddress ?: $c->customerName),
                'to' => $e->toAddress,
                'time' => $e->occurredAt->format('Y-m-d H:i'),
                'body' => mb_substr(trim((string) ($e->bodyText ?: strip_tags((string) $e->bodyHtml))), 0, 8000),
            ];
        }

        return $this->json([
            'id' => $c->id,
            'subject' => $c->subject,
            'customerName' => $c->customerName,
            'customerEmail' => $c->customerEmail,
            'taskId' => $task?->id,
            'messages' => $messages,
        ]);
    }

    #[Route('/api/conversations/{id}/to-task', methods: ['POST'])]
    public function toTask(Conversation $c): JsonResponse
    {
        // Idempotent: existiert schon eine Aufgabe, diese zurückgeben.
        if ($existing = $this->tasksByConversation()[$c->id] ?? null) {
            return $this->json(['ok' => true, 'taskId' => $existing->id, 'existing' => true]);
        }

        // Letzte eingehende Mail triagieren.
        $lastIn = null;
        foreach ($c->emails as $e) {
            if ('in' === $e->direction) {
                $lastIn = $e;
            }
        }
        if (!$lastIn) {
            return $this->json(['error' => 'Keine eingehende Mail.'], 422);
        }

        $task = $this->triage->triage($lastIn);
        if (!$task) {
            // KI sah keine Aufgabe -> auf Wunsch des Users trotzdem eine anlegen.
            $task = new Task();
            $task->title = $c->subject ?: '(ohne Titel)';
            $task->conversation = $c;
            $task->sourceEmail = $lastIn;
            $task->aiSummary = mb_substr(trim((string) ($lastIn->bodyText ?: strip_tags((string) $lastIn->bodyHtml))), 0, 280);
            $this->em->persist($task);
            $this->em->flush();
        }

        return $this->json(['ok' => true, 'taskId' => $task->id]);
    }

    /** @return array<int, Task> Konversations-ID => zuletzt angelegte Aufgabe */
    private function tasksByConversation(): array
    {
        $map = [];
        foreach ($this->em->getRepository(Task::class)->findBy([], ['id' => 'ASC']) as $t) {
            if ($t->conversation?->id) {
                $map[$t->conversation->id] = $t; // letzte gewinnt
            }
        }

        return $map;
    }
}
