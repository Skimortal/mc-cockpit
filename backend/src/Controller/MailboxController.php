<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Postfach-Verwaltung. Global = nur Admin; persönlich = nur der Eigentümer.
 * Auch ein Admin sieht/verwaltet NICHT die persönlichen Postfächer anderer.
 */
class MailboxController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/mailboxes/manage', methods: ['GET'])]
    public function manage(#[CurrentUser] ?User $user): JsonResponse
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        $all = $this->em->getRepository(Mailbox::class)->findBy([], ['scope' => 'ASC', 'name' => 'ASC']);
        $out = [];
        foreach ($all as $m) {
            if ($this->canManage($m, $user, $admin)) {
                $out[] = $this->arr($m);
            }
        }

        return $this->json($out);
    }

    #[Route('/api/mailboxes', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        $scope = ('global' === ($d['scope'] ?? 'personal')) ? 'global' : 'personal';
        if ('global' === $scope && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Nur Admin darf globale Postfächer anlegen.'], 403);
        }
        $m = new Mailbox();
        $m->scope = $scope;
        $m->owner = 'personal' === $scope ? $user : null; // persönlich gehört immer dem aktuellen User
        $this->apply($m, $d);
        $this->em->persist($m);
        $this->em->flush();

        return $this->json($this->arr($m), 201);
    }

    #[Route('/api/mailboxes/{id}', methods: ['PATCH'])]
    public function update(Mailbox $m, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canManage($m, $user, $this->isGranted('ROLE_ADMIN'))) {
            return $this->json(['error' => 'Kein Zugriff auf dieses Postfach.'], 403);
        }
        $this->apply($m, json_decode($request->getContent(), true) ?: []);
        $this->em->flush();

        return $this->json($this->arr($m));
    }

    #[Route('/api/mailboxes/{id}', methods: ['DELETE'])]
    public function delete(Mailbox $m, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canManage($m, $user, $this->isGranted('ROLE_ADMIN'))) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }
        $this->em->remove($m);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** @param array<string,mixed> $d */
    private function apply(Mailbox $m, array $d): void
    {
        foreach (['name', 'email', 'imapHost', 'imapEncryption', 'smtpHost', 'smtpEncryption', 'username'] as $f) {
            if (isset($d[$f])) {
                $m->$f = (string) $d[$f];
            }
        }
        foreach (['imapPort', 'smtpPort', 'attachmentRetentionMonths', 'mailRetentionMonths'] as $f) {
            if (isset($d[$f])) {
                $m->$f = max(0, (int) $d[$f]);
            }
        }
        if (isset($d['active'])) {
            $m->active = (bool) $d['active'];
        }
        if (\array_key_exists('defaultSignatureId', $d)) {
            $sid = $d['defaultSignatureId'];
            $m->defaultSignature = $sid ? $this->em->getRepository(\App\Entity\Signature::class)->find($sid) : null;
        }
        if (!empty($d['password'])) {
            $m->password = (string) $d['password']; // nur setzen, wenn übergeben
        }
        if ('' === $m->username) {
            $m->username = $m->email;
        }
    }

    private function canManage(Mailbox $m, ?User $user, bool $admin): bool
    {
        if ('global' === $m->scope) {
            return $admin;
        }

        return $user && $m->owner && $m->owner->getId() === $user->getId();
    }

    /** @return array<string,mixed> Passwort wird NIE ausgeliefert. */
    private function arr(Mailbox $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'email' => $m->email,
            'scope' => $m->scope,
            'owner' => $m->owner ? ['id' => $m->owner->getId(), 'name' => trim($m->owner->getFirstName().' '.$m->owner->getLastName())] : null,
            'imapHost' => $m->imapHost,
            'imapPort' => $m->imapPort,
            'imapEncryption' => $m->imapEncryption,
            'smtpHost' => $m->smtpHost,
            'smtpPort' => $m->smtpPort,
            'smtpEncryption' => $m->smtpEncryption,
            'username' => $m->username,
            'active' => $m->active,
            'hasPassword' => '' !== $m->password,
            'attachmentRetentionMonths' => $m->attachmentRetentionMonths,
            'mailRetentionMonths' => $m->mailRetentionMonths,
            'defaultSignatureId' => $m->defaultSignature?->id,
        ];
    }
}
