<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Mailbox;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Triage\EmailTriageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Posteingang (Konversationen) + „Mail → Aufgabe" — mit Postfach-Sichtbarkeit (global/persönlich). */
class InboxController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailTriageService $triage,
    ) {
    }

    #[Route('/api/mailboxes', methods: ['GET'])]
    public function mailboxes(#[CurrentUser] ?User $user): JsonResponse
    {
        $out = [];
        foreach ($this->visibleMailboxes($user) as $m) {
            $out[] = [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'scope' => $m->scope,
                'owner' => $m->owner ? trim($m->owner->getFirstName().' '.$m->owner->getLastName()) : null,
                'mine' => $user && $m->owner && $m->owner->getId() === $user->getId(),
            ];
        }

        return $this->json($out);
    }

    #[Route('/api/inbox', methods: ['GET'])]
    public function inbox(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $visible = $this->visibleMailboxes($user);
        $visibleIds = array_map(fn (Mailbox $m) => $m->id, $visible);

        // optionaler Filter über den Postfach-Switcher
        $filter = $request->query->get('mailbox'); // ID | 'global' | 'mine' | null(=alle)
        $byConv = $this->tasksByConversation();
        $convs = $this->em->getRepository(Conversation::class)->findBy([], [], 400);

        $rows = [];
        foreach ($convs as $c) {
            $mb = $c->mailbox;
            if (!$mb || !\in_array($mb->id, $visibleIds, true)) {
                continue; // nicht sichtbar für diesen User
            }
            if ('global' === $filter && 'global' !== $mb->scope) {
                continue;
            }
            if ('mine' === $filter && !($mb->owner && $user && $mb->owner->getId() === $user->getId())) {
                continue;
            }
            if (is_numeric($filter) && (int) $filter !== $mb->id) {
                continue;
            }

            // Effektives Datum = neueste Nachricht im Thread (Fallback lastMessageAt/createdAt).
            $eff = $c->emails->last() ? $c->emails->last()->occurredAt : ($c->lastMessageAt ?? $c->createdAt);
            $task = $byConv[$c->id] ?? null;
            $rows[] = [
                '_ts' => $eff->getTimestamp(),
                'data' => [
                    'id' => $c->id,
                    'from' => $c->customerName ?: $c->customerEmail,
                    'email' => $c->customerEmail,
                    'subject' => $c->subject,
                    'lastMessageAt' => $eff->format('Y-m-d H:i'),
                    'messageCount' => $c->emails->count(),
                    'state' => $task ? ('done' === $task->status ? 'erledigt' : 'aufgabe') : 'neu',
                    'taskId' => $task?->id,
                    'owner' => $task?->assignee ? trim($task->assignee->getFirstName().' '.$task->assignee->getLastName()) : null,
                    'mailboxId' => $mb->id,
                    'mailboxName' => $mb->name,
                    'mailboxScope' => $mb->scope,
                ],
            ];
        }

        // Neueste zuerst.
        usort($rows, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);

        return $this->json(array_map(fn ($r) => $r['data'], $rows));
    }

    #[Route('/api/conversations/{id}', methods: ['GET'])]
    public function conversation(Conversation $c, #[CurrentUser] ?User $user): JsonResponse
    {
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return $this->json(['error' => 'Kein Zugriff auf diese Konversation.'], 403);
        }

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
            'taskId' => ($this->tasksByConversation()[$c->id] ?? null)?->id,
            'messages' => $messages,
        ]);
    }

    #[Route('/api/conversations/{id}/to-task', methods: ['POST'])]
    public function toTask(Conversation $c, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->maySee($c, $user, false)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }
        if ($existing = $this->tasksByConversation()[$c->id] ?? null) {
            return $this->json(['ok' => true, 'taskId' => $existing->id, 'existing' => true]);
        }

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

    /** Posteingang-Sichtbarkeit: global ODER eigenes persönliches ODER (geteilt, weil Aufgabe existiert). */
    private function maySee(Conversation $c, ?User $user, bool $hasTask): bool
    {
        $mb = $c->mailbox;
        if (!$mb) {
            return $hasTask;
        }
        if ('global' === $mb->scope) {
            return true;
        }
        if ($user && $mb->owner && $mb->owner->getId() === $user->getId()) {
            return true;
        }

        return $hasTask; // persönliche Mail wird mit dem Umwandeln zur Aufgabe fürs Team sichtbar
    }

    /** @return list<Mailbox> globale + eigene persönliche Postfächer */
    private function visibleMailboxes(?User $user): array
    {
        $all = $this->em->getRepository(Mailbox::class)->findBy(['active' => true], ['name' => 'ASC']);

        return array_values(array_filter(
            $all,
            fn (Mailbox $m) => 'global' === $m->scope || ($user && $m->owner && $m->owner->getId() === $user->getId())
        ));
    }

    /** @return array<int, Task> Konversations-ID => Aufgabe */
    private function tasksByConversation(): array
    {
        $map = [];
        foreach ($this->em->getRepository(Task::class)->findBy([], ['id' => 'ASC']) as $t) {
            if ($t->conversation?->id) {
                $map[$t->conversation->id] = $t;
            }
        }

        return $map;
    }
}
