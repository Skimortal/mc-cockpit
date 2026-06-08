<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Maßgeschneiderte Endpunkte fürs Aufgaben-Board (schlanke, fertige JSON-Payloads). */
class BoardController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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

    #[Route('/api/tasks/{id}/assign', methods: ['POST'])]
    public function assign(Task $task, Request $request): JsonResponse
    {
        $userId = json_decode($request->getContent(), true)['userId'] ?? null;
        $task->assignee = $userId ? $this->em->getRepository(User::class)->find($userId) : null;
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
            'aiSummary' => $t->aiSummary,
            'suggestedAssignee' => $t->suggestedAssignee,
            'assignee' => $t->assignee ? $this->userArr($t->assignee) : null,
            'conversationId' => $t->conversation?->id,
            'companyName' => $t->company?->name,
            'tenderName' => $t->tender?->name,
            'source' => $src ? [
                'subject' => $src->subject,
                'from' => $src->fromAddress,
                'occurredAt' => $src->occurredAt->format('Y-m-d H:i'),
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
