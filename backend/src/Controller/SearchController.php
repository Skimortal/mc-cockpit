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
    private const HL_PRE = '⟦';
    private const HL_POST = '⟧';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexer $indexer,
    ) {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = min(50, max(1, (int) $request->query->get('limit', 8)));
        if (mb_strlen($q) < 2) {
            return $this->json(['tasks' => [], 'conversations' => [], 'companies' => []]);
        }

        try {
            return $this->json($this->meili($q, $user, $limit));
        } catch (\Throwable) {
            return $this->json($this->fallback($q, $user, $limit));
        }
    }

    /** @return array<string,mixed> */
    private function meili(string $q, ?User $user, int $limit): array
    {
        $uid = $user?->getId() ?? 0;
        $c = $this->indexer->client();

        $hl = ['highlightPreTag' => self::HL_PRE, 'highlightPostTag' => self::HL_POST];

        $tasks = $c->index(SearchIndexer::TASKS)->search($q, [
            'limit' => $limit,
            'attributesToHighlight' => ['title', 'summary'],
            'attributesToCrop' => ['summary'], 'cropLength' => 24,
        ] + $hl)->getHits();

        $filter = sprintf('mailboxScope = "global" OR ownerId = %d OR hasTask = true', $uid);
        $convs = $c->index(SearchIndexer::CONVERSATIONS)->search($q, [
            'limit' => $limit, 'filter' => $filter,
            'attributesToHighlight' => ['subject', 'body'],
            'attributesToCrop' => ['body'], 'cropLength' => 26,
        ] + $hl)->getHits();

        $companies = $c->index(SearchIndexer::COMPANIES)->search($q, [
            'limit' => $limit,
            'attributesToHighlight' => ['name', 'subtitle', 'fields'],
            'attributesToCrop' => ['fields'], 'cropLength' => 22,
        ] + $hl)->getHits();

        return [
            'tasks' => array_map(fn ($h) => [
                'id' => $h['id'], 'title' => $h['title'] ?? '', 'titleHl' => $this->full($h, 'title'),
                'status' => $h['status'] ?? null, 'conversationId' => $h['conversationId'] ?? null,
                'snippet' => $this->crop($h, 'summary'),
            ], $tasks),
            'conversations' => array_map(fn ($h) => [
                'id' => $h['id'], 'subject' => $h['subject'] ?? '', 'subjectHl' => $this->full($h, 'subject'),
                'from' => $h['from'] ?? null, 'hasTask' => $h['hasTask'] ?? false,
                'date' => $h['date'] ?? null, 'mailbox' => $h['mailboxName'] ?? null,
                'snippet' => $this->crop($h, 'body'),
            ], $convs),
            'companies' => array_map(fn ($h) => [
                'id' => $h['id'], 'name' => $h['name'] ?? '', 'nameHl' => $this->full($h, 'name'),
                'subtitle' => $h['subtitle'] ?? null, 'snippet' => $this->crop($h, 'fields'),
            ], $companies),
        ];
    }

    /** Volltext-Feld mit Hervorhebungs-Markierungen (gekürzt). */
    private function full(array $h, string $f): string
    {
        return mb_substr(trim((string) ($h['_formatted'][$f] ?? $h[$f] ?? '')), 0, 300);
    }

    /** Gekürztes Snippet eines Feldes (Whitespace normalisiert), nur wenn ein Treffer drinsteckt. */
    private function crop(array $h, string $f): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) ($h['_formatted'][$f] ?? '')));
        if (!str_contains($s, self::HL_PRE)) {
            return ''; // kein Treffer in diesem Feld -> kein Snippet
        }

        return mb_substr($s, 0, 240);
    }

    /** Postgres-Fallback (Teilstring). @return array<string,mixed> */
    private function fallback(string $q, ?User $user, int $limit = 8): array
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
        $taskOut = array_map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'titleHl' => $t->title, 'status' => $t->status, 'conversationId' => $t->conversation?->id, 'snippet' => ''], $tasks);

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
            $convOut[] = ['id' => $c->id, 'subject' => $c->subject, 'subjectHl' => $c->subject, 'from' => $c->customerName ?: $c->customerEmail, 'hasTask' => isset($taskConvIds[$c->id]), 'date' => $c->lastMessageAt?->format('Y-m-d H:i'), 'mailbox' => $c->mailbox?->name, 'snippet' => ''];
            if (\count($convOut) >= $limit) {
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
                $companyOut[] = ['id' => $co->id, 'name' => $co->name, 'nameHl' => $co->name, 'subtitle' => $co->subtitle, 'snippet' => ''];
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
