<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Search\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Globale Suche über Aufgaben, Konversationen (sichtbarkeits-bewusst) und Kunden.
 * Primär über Meilisearch (tippfehlertolerant); Postgres-ILIKE als Fallback, falls Meili nicht antwortet.
 */
class SearchController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexer $indexer,
    ) {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json(['tasks' => [], 'conversations' => [], 'companies' => []]);
        }

        try {
            return $this->json($this->meili($q, $user));
        } catch (\Throwable) {
            return $this->json($this->fallback($q, $user));
        }
    }

    /** @return array<string,mixed> */
    private function meili(string $q, ?User $user): array
    {
        $uid = $user?->getId() ?? 0;
        $c = $this->indexer->client();

        $tasks = $c->index(SearchIndexer::TASKS)->search($q, ['limit' => 8])->getHits();
        $filter = sprintf('mailboxScope = "global" OR ownerId = %d OR hasTask = true', $uid);
        $convs = $c->index(SearchIndexer::CONVERSATIONS)->search($q, ['limit' => 8, 'filter' => $filter])->getHits();
        $companies = $c->index(SearchIndexer::COMPANIES)->search($q, ['limit' => 8])->getHits();

        return [
            'tasks' => array_map(fn ($h) => ['id' => $h['id'], 'title' => $h['title'] ?? '', 'status' => $h['status'] ?? null, 'conversationId' => $h['conversationId'] ?? null], $tasks),
            'conversations' => array_map(fn ($h) => ['id' => $h['id'], 'subject' => $h['subject'] ?? '', 'from' => $h['from'] ?? null, 'hasTask' => $h['hasTask'] ?? false], $convs),
            'companies' => array_map(fn ($h) => ['id' => $h['id'], 'name' => $h['name'] ?? '', 'subtitle' => $h['subtitle'] ?? null], $companies),
        ];
    }

    /** Postgres-Fallback (Teilstring). @return array<string,mixed> */
    private function fallback(string $q, ?User $user): array
    {
        $like = '%'.mb_strtolower($q).'%';

        $taskConvIds = [];
        foreach ($this->em->getRepository(Task::class)->findAll() as $t) {
            if ($t->conversation?->id) {
                $taskConvIds[$t->conversation->id] = true;
            }
        }

        $tasks = $this->em->createQueryBuilder()
            ->select('t')->from(Task::class, 't')
            ->where('LOWER(t.title) LIKE :q OR LOWER(t.aiSummary) LIKE :q')
            ->setParameter('q', $like)->orderBy('t.createdAt', 'DESC')->setMaxResults(8)
            ->getQuery()->getResult();
        $taskOut = array_map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'conversationId' => $t->conversation?->id], $tasks);

        $convs = $this->em->createQueryBuilder()
            ->select('DISTINCT c')->from(Conversation::class, 'c')->leftJoin('c.emails', 'e')
            ->where('LOWER(c.subject) LIKE :q OR LOWER(c.customerName) LIKE :q OR LOWER(c.customerEmail) LIKE :q OR LOWER(e.bodyText) LIKE :q')
            ->setParameter('q', $like)->setMaxResults(40)
            ->getQuery()->getResult();
        $convOut = [];
        foreach ($convs as $c) {
            if (!$this->maySee($c, $user, isset($taskConvIds[$c->id]))) {
                continue;
            }
            $convOut[] = ['id' => $c->id, 'subject' => $c->subject, 'from' => $c->customerName ?: $c->customerEmail, 'hasTask' => isset($taskConvIds[$c->id])];
            if (\count($convOut) >= 8) {
                break;
            }
        }

        $companyOut = [];
        foreach ($this->em->getRepository(Company::class)->findAll() as $co) {
            $hay = mb_strtolower($co->name.' '.$co->subtitle.' '.implode(' ', $co->tags));
            foreach ($co->customFields as $f) {
                $hay .= ' '.mb_strtolower(($f['label'] ?? '').' '.($f['value'] ?? ''));
            }
            if (str_contains($hay, mb_strtolower($q))) {
                $companyOut[] = ['id' => $co->id, 'name' => $co->name, 'subtitle' => $co->subtitle];
            }
            if (\count($companyOut) >= 8) {
                break;
            }
        }

        return ['tasks' => $taskOut, 'conversations' => $convOut, 'companies' => $companyOut];
    }

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

        return $hasTask;
    }
}
