<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Benutzerverwaltung – nur für Admins. */
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/api/users', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->json(array_map(fn (User $u) => $this->arr($u), $this->em->getRepository(User::class)->findBy([], ['firstName' => 'ASC'])));
    }

    #[Route('/api/users', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $d = json_decode($request->getContent(), true) ?: [];
        if (empty($d['email']) || empty($d['password'])) {
            return $this->json(['error' => 'E-Mail und Passwort nötig.'], 400);
        }
        if ($this->em->getRepository(User::class)->findOneBy(['email' => $d['email']])) {
            return $this->json(['error' => 'E-Mail bereits vergeben.'], 409);
        }
        $u = new User();
        $u->setEmail($d['email']);
        $u->setFirstName($d['firstName'] ?? '');
        $u->setLastName($d['lastName'] ?? '');
        $u->setRoles(!empty($d['admin']) ? ['ROLE_ADMIN'] : []);
        $u->setPassword($this->hasher->hashPassword($u, $d['password']));
        $this->em->persist($u);
        $this->em->flush();

        return $this->json($this->arr($u), 201);
    }

    #[Route('/api/users/{id}', methods: ['PATCH'])]
    public function update(User $u, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $d = json_decode($request->getContent(), true) ?: [];
        if (isset($d['firstName'])) {
            $u->setFirstName($d['firstName']);
        }
        if (isset($d['lastName'])) {
            $u->setLastName($d['lastName']);
        }
        if (isset($d['email']) && $d['email'] !== $u->getEmail()) {
            if ($this->em->getRepository(User::class)->findOneBy(['email' => $d['email']])) {
                return $this->json(['error' => 'E-Mail bereits vergeben.'], 409);
            }
            $u->setEmail($d['email']);
        }
        if (\array_key_exists('admin', $d)) {
            $u->setRoles($d['admin'] ? ['ROLE_ADMIN'] : []);
        }
        if (!empty($d['password'])) {
            $u->setPassword($this->hasher->hashPassword($u, $d['password']));
        }
        $this->em->flush();

        return $this->json($this->arr($u));
    }

    #[Route('/api/users/{id}', methods: ['DELETE'])]
    public function delete(User $u, #[CurrentUser] ?User $me): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($me && $me->getId() === $u->getId()) {
            return $this->json(['error' => 'Sich selbst kann man nicht löschen.'], 400);
        }
        // FK-sicher: zugewiesene Aufgaben lösen, persönliche Postfächer entfernen.
        foreach ($this->em->getRepository(Task::class)->findBy(['assignee' => $u]) as $t) {
            $t->assignee = null;
        }
        foreach ($this->em->getRepository(Mailbox::class)->findBy(['owner' => $u]) as $m) {
            $this->em->remove($m);
        }
        $this->em->remove($u);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function arr(User $u): array
    {
        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstName' => $u->getFirstName(),
            'lastName' => $u->getLastName(),
            'name' => trim($u->getFirstName().' '.$u->getLastName()) ?: $u->getEmail(),
            'isAdmin' => \in_array('ROLE_ADMIN', $u->getRoles(), true),
        ];
    }
}
