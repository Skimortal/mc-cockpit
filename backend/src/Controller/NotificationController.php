<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Util\Tz;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** In-App-Benachrichtigungen des angemeldeten Benutzers. */
class NotificationController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/notifications', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $repo = $this->em->getRepository(Notification::class);
        $items = $repo->findBy(['user' => $user], ['createdAt' => 'DESC'], 30);
        $unread = $repo->count(['user' => $user, 'isRead' => false]);

        return $this->json([
            'unread' => $unread,
            'items' => array_map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'text' => $n->text,
                'taskId' => $n->taskId,
                'conversationId' => $n->conversationId,
                'read' => $n->isRead,
                'createdAt' => Tz::fmt($n->createdAt),
            ], $items),
        ]);
    }

    #[Route('/api/notifications/read-all', methods: ['POST'])]
    public function readAll(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->em->createQuery('UPDATE App\Entity\Notification n SET n.isRead = true WHERE n.user = :u AND n.isRead = false')
            ->setParameter('u', $user)->execute();

        return $this->json(['ok' => true]);
    }

    #[Route('/api/notifications/{id}/read', methods: ['POST'])]
    public function read(Notification $n, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($n->user?->getId() === $user?->getId()) {
            $n->isRead = true;
            $this->em->flush();
        }

        return $this->json(['ok' => true]);
    }
}
