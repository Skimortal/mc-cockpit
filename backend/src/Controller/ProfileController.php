<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Eigene Profildaten (jeder Benutzer für sich). */
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/api/me', methods: ['PATCH'])]
    public function update(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'unauthenticated'], 401);
        }
        $d = json_decode($request->getContent(), true) ?: [];
        if (isset($d['firstName'])) {
            $user->setFirstName($d['firstName']);
        }
        if (isset($d['lastName'])) {
            $user->setLastName($d['lastName']);
        }
        if (isset($d['email']) && $d['email'] !== $user->getEmail()) {
            if ($this->em->getRepository(User::class)->findOneBy(['email' => $d['email']])) {
                return $this->json(['error' => 'E-Mail bereits vergeben.'], 409);
            }
            $user->setEmail($d['email']);
        }
        if (!empty($d['password'])) {
            $user->setPassword($this->hasher->hashPassword($user, $d['password']));
        }
        $this->em->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'name' => trim($user->getFirstName().' '.$user->getLastName()) ?: $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }
}
