<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\User;
use App\Mail\Mailer;
use App\Util\Tz;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Maßgeschneiderte Endpunkte fürs Aufgaben-Board (schlanke, fertige JSON-Payloads). */
class BoardController extends AbstractController
{
    private const BASE_URL = 'https://crm.most-connect.com';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Mailer $mailer,
    ) {
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'unauthenticated'], 401);
        }

        return $this->json($this->userArr($user));
    }

    #[Route('/api/team', methods: ['GET'])]
    public function team(): JsonResponse
    {
        $users = $this->em->getRepository(User::class)->findBy([], ['firstName' => 'ASC']);

        return $this->json(array_map(fn (User $u) => $this->userArr($u), $users));
    }

    #[Route('/api/board', methods: ['GET'])]
    public function board(): JsonResponse
    {
        $tasks = $this->em->getRepository(Task::class)->findBy([], ['createdAt' => 'DESC'], 300);

        return $this->json(array_map(fn (Task $t) => $this->taskArr($t), $tasks));
    }

    /** Manuelle Aufgabe ohne Mail-Bezug anlegen (volles Formular). */
    #[Route('/api/tasks', methods: ['POST'])]
    public function createTask(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        $title = trim((string) ($d['title'] ?? ''));
        if ('' === $title) {
            return $this->json(['error' => 'Titel fehlt.'], 422);
        }

        $t = new Task();
        $t->title = mb_substr($title, 0, 255);
        $t->description = trim((string) ($d['description'] ?? '')) ?: null;
        $t->status = \in_array($d['status'] ?? '', ['open', 'in_progress', 'waiting', 'done'], true) ? $d['status'] : 'open';
        $t->priority = \in_array($d['priority'] ?? '', ['low', 'normal', 'high'], true) ? $d['priority'] : 'normal';
        $due = trim((string) ($d['dueDate'] ?? ''));
        $t->dueDate = $due ? new \DateTimeImmutable($due.' 09:00') : null;
        $tags = $d['tags'] ?? [];
        $t->tags = \is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
        if (!empty($d['assigneeId'])) {
            $t->assignee = $this->em->getRepository(User::class)->find($d['assigneeId']);
        }
        if (!empty($d['companyId'])) {
            $t->company = $this->em->getRepository(Company::class)->find($d['companyId']);
        }
        $this->em->persist($t);
        $this->em->flush();

        // Zuweisung an jemand anderen: In-App benachrichtigen, optional auch per E-Mail (wie bei assign()).
        $emailed = false;
        if ($t->assignee && $user && $t->assignee->getId() !== $user->getId()) {
            $by = $user->getFirstName() ?: $user->getEmail();
            $this->notify($t->assignee, $user, 'assigned', sprintf('%s hat dir „%s" zugewiesen.', $by, $t->title), $t);
            $this->em->flush();
            if (($d['notify'] ?? true) && $t->assignee->getEmail()) {
                $emailed = $this->emailAssignee($t, $user);
            }
        }

        $out = $this->taskArr($t);
        $out['emailed'] = $emailed;

        return $this->json($out, 201);
    }

    #[Route('/api/tasks/{id}/assign', methods: ['POST'])]
    public function assign(Task $task, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $userId = json_decode($request->getContent(), true)['userId'] ?? null;
        $previousId = $task->assignee?->getId();
        $task->assignee = $userId ? $this->em->getRepository(User::class)->find($userId) : null;
        if ($task->assignee) {
            $by = $user ? ($user->getFirstName() ?: $user->getEmail()) : 'Jemand';
            $this->notify($task->assignee, $user, 'assigned', sprintf('%s hat dir „%s" zugewiesen.', $by, $task->title), $task);
        }
        $this->em->flush();

        // Bei NEUER Zuweisung (anderer Empfänger) automatisch eine E-Mail schicken – best effort.
        $emailed = false;
        if ($task->assignee && $task->assignee->getId() !== $previousId && $task->assignee->getEmail()) {
            $emailed = $this->emailAssignee($task, $user);
        }

        $out = $this->taskArr($task);
        $out['emailed'] = $emailed;

        return $this->json($out);
    }

    #[Route('/api/tasks/{id}/priority', methods: ['POST'])]
    public function priority(Task $task, Request $request): JsonResponse
    {
        $p = json_decode($request->getContent(), true)['priority'] ?? null;
        if (!\in_array($p, ['low', 'normal', 'high'], true)) {
            return $this->json(['error' => 'invalid priority'], 400);
        }
        $task->priority = $p;
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    /** Manuell: den aktuell Zuständigen (erneut) per E-Mail benachrichtigen (optional mit Nachricht). */
    #[Route('/api/tasks/{id}/notify-assignee', methods: ['POST'])]
    public function notifyAssignee(Task $task, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$task->assignee || !$task->assignee->getEmail()) {
            return $this->json(['error' => 'Aufgabe ist niemandem mit E-Mail-Adresse zugewiesen.'], 422);
        }
        $note = trim((string) (json_decode($request->getContent(), true)['message'] ?? ''));
        if (!$this->emailAssignee($task, $user, $note ?: null)) {
            return $this->json(['error' => 'E-Mail konnte nicht versendet werden (SMTP-Postfach/Passwort prüfen).'], 502);
        }

        return $this->json(['ok' => true, 'to' => $task->assignee->getEmail()]);
    }

    private function emailAssignee(Task $task, ?User $actor, ?string $note = null): bool
    {
        $assignee = $task->assignee;
        if (!$assignee || !$assignee->getEmail()) {
            return false;
        }
        $fileCount = $this->em->getRepository(\App\Entity\TaskFile::class)->count(['task' => $task]);
        $convId = $task->conversation?->id;
        $url = self::BASE_URL.'/aufgaben'.($convId ? '?conv='.$convId : '');
        $toName = trim($assignee->getFirstName().' '.$assignee->getLastName()) ?: $assignee->getEmail();
        $actorName = $actor ? (trim($actor->getFirstName().' '.$actor->getLastName()) ?: $actor->getEmail()) : null;
        try {
            $this->mailer->sendAssignmentNotice($task, $assignee->getEmail(), $toName, $actorName, $url, $fileCount, $note);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[Route('/api/tasks/{id}/due', methods: ['POST'])]
    public function due(Task $task, Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true)['dueDate'] ?? null; // 'Y-m-d' oder null
        $task->dueDate = $d ? new \DateTimeImmutable($d.' 09:00') : null;
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    #[Route('/api/tasks/{id}/company', methods: ['POST'])]
    public function setCompany(Task $task, Request $request): JsonResponse
    {
        $companyId = json_decode($request->getContent(), true)['companyId'] ?? null;
        $task->company = $companyId ? $this->em->getRepository(Company::class)->find($companyId) : null;
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    #[Route('/api/tasks/{id}/status', methods: ['POST'])]
    public function status(Task $task, Request $request): JsonResponse
    {
        $status = json_decode($request->getContent(), true)['status'] ?? null;
        if (!\in_array($status, ['open', 'in_progress', 'waiting', 'done'], true)) {
            return $this->json(['error' => 'invalid status'], 400);
        }
        $task->status = $status;
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    #[Route('/api/tasks/{id}/tags', methods: ['POST'])]
    public function tags(Task $task, Request $request): JsonResponse
    {
        $tags = json_decode($request->getContent(), true)['tags'] ?? [];
        $task->tags = \is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    #[Route('/api/tasks/{id}/comments', methods: ['POST'])]
    public function comment(Task $task, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $body = trim((string) (json_decode($request->getContent(), true)['body'] ?? ''));
        if ('' === $body) {
            return $this->json(['error' => 'Leerer Kommentar.'], 400);
        }
        $c = new TaskComment();
        $c->task = $task;
        $c->authorName = $user ? (trim($user->getFirstName().' '.$user->getLastName()) ?: $user->getEmail()) : '?';
        $c->body = $body;
        $this->em->persist($c);
        if ($task->assignee) {
            $by = $user ? ($user->getFirstName() ?: $user->getEmail()) : 'Jemand';
            $this->notify($task->assignee, $user, 'comment', sprintf('%s hat „%s" kommentiert.', $by, $task->title), $task);
        }
        $this->em->flush();

        return $this->json($this->taskArr($task));
    }

    /** Legt eine Benachrichtigung an (nicht für sich selbst). Flush übernimmt der Aufrufer. */
    private function notify(?User $to, ?User $actor, string $type, string $text, Task $task): void
    {
        if (!$to || ($actor && $to->getId() === $actor->getId())) {
            return;
        }
        $n = new Notification();
        $n->user = $to;
        $n->type = $type;
        $n->text = $text;
        $n->taskId = $task->id;
        $n->conversationId = $task->conversation?->id;
        $this->em->persist($n);
    }

    /** @return array<string,mixed> */
    private function taskArr(Task $t): array
    {
        $src = $t->sourceEmail;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'type' => $t->type,
            'status' => $t->status,
            'priority' => $t->priority,
            'dueDate' => $t->dueDate?->format('Y-m-d'),
            'overdue' => $t->dueDate && 'done' !== $t->status && $t->dueDate < new \DateTimeImmutable('today'),
            'aiSummary' => $t->aiSummary,
            'description' => $t->description,
            'suggestedAssignee' => $t->suggestedAssignee,
            'assignee' => $t->assignee ? $this->userArr($t->assignee) : null,
            'conversationId' => $t->conversation?->id,
            'companyId' => $t->company?->id,
            'companyName' => $t->company?->name,
            'tenderName' => $t->tender?->name,
            'tags' => $t->tags,
            'comments' => array_map(fn (TaskComment $k) => [
                'author' => $k->authorName,
                'body' => $k->body,
                'createdAt' => Tz::fmt($k->createdAt),
            ], $t->comments->toArray()),
            'files' => array_map(fn (\App\Entity\TaskFile $f) => [
                'id' => $f->id,
                'name' => $f->filename,
                'size' => $f->size,
                'ext' => strtoupper((string) (pathinfo($f->filename, \PATHINFO_EXTENSION) ?: 'DATEI')),
                'uploadedBy' => $f->uploadedBy,
                'date' => $f->createdAt->format('Y-m-d'),
                'preview' => str_starts_with(mb_strtolower((string) $f->contentType), 'image/')
                    || str_contains(mb_strtolower((string) $f->contentType), 'pdf')
                    || (bool) preg_match('/\.(pdf|png|jpe?g|gif|webp|docx?|xlsx?|pptx?|odt|ods|odp)$/i', $f->filename),
            ], $this->em->getRepository(\App\Entity\TaskFile::class)->findBy(['task' => $t], ['id' => 'ASC'])),
            'source' => $src ? [
                'subject' => $src->subject,
                'from' => $src->fromAddress,
                'occurredAt' => Tz::fmt($src->occurredAt),
                'bodyText' => mb_substr((string) ($src->bodyText ?: strip_tags((string) $src->bodyHtml)), 0, 5000),
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function userArr(User $u): array
    {
        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstName' => $u->getFirstName(),
            'lastName' => $u->getLastName(),
            'name' => trim($u->getFirstName().' '.$u->getLastName()) ?: $u->getEmail(),
            'roles' => $u->getRoles(),
        ];
    }
}
