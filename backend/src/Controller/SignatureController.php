<?php

namespace App\Controller;

use App\Entity\Signature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Verwaltbare E-Mail-Signaturen. */
class SignatureController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/signatures', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $sigs = $this->em->getRepository(Signature::class)->findBy([], ['name' => 'ASC']);

        return $this->json(array_map([$this, 'arr'], $sigs));
    }

    #[Route('/api/signatures', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        if (empty(trim((string) ($d['name'] ?? '')))) {
            return $this->json(['error' => 'Name fehlt.'], 400);
        }
        $s = new Signature();
        $s->name = mb_substr(trim((string) $d['name']), 0, 120);
        $s->html = (string) ($d['html'] ?? '');
        $this->em->persist($s);
        $this->em->flush();

        return $this->json($this->arr($s), 201);
    }

    #[Route('/api/signatures/{id}', methods: ['PATCH'])]
    public function update(Signature $s, Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        if (\array_key_exists('name', $d) && '' !== trim((string) $d['name'])) {
            $s->name = mb_substr(trim((string) $d['name']), 0, 120);
        }
        if (\array_key_exists('html', $d)) {
            $s->html = (string) $d['html'];
        }
        $this->em->flush();

        return $this->json($this->arr($s));
    }

    #[Route('/api/signatures/{id}', methods: ['DELETE'])]
    public function delete(Signature $s): JsonResponse
    {
        $this->em->remove($s);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function arr(Signature $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'html' => $s->html];
    }
}
