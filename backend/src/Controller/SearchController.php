<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Search\SearchIndexer;
use App\Util\Tz;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Globale Suche über Aufgaben, Mails, Dokumente (Uploads + Mail-Anhänge) und Kunden –
 * sichtbarkeits-bewusst. Primär Meilisearch (tippfehlertolerant), Postgres-ILIKE als Fallback.
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
        $limit = min(100, max(1, (int) $request->query->get('limit', 6)));
        if (mb_strlen($q) < 2) {
            return $this->json($this->empty());
        }

        try {
            return $this->json($this->meili($q, $user, $limit));
        } catch (\Throwable) {
            return $this->json($this->fallback($q, $user, $limit));
        }
    }

    /** @return array<string,mixed> */
    private function empty(): array
    {
        return ['tasks' => [], 'conversations' => [], 'companies' => [], 'documents' => [],
            'counts' => ['tasks' => 0, 'conversations' => 0, 'companies' => 0, 'documents' => 0]];
    }

    /** @return array<string,mixed> */
    private function meili(string $q, ?User $user, int $limit): array
    {
        $uid = $user?->getId() ?? 0;
        $c = $this->indexer->client();
        $hl = ['highlightPreTag' => self::HL_PRE, 'highlightPostTag' => self::HL_POST];
        $filter = sprintf('mailboxScope = "global" OR ownerId = %d OR hasTask = true', $uid);

        $taskRes = $c->index(SearchIndexer::TASKS)->search($q, [
            'limit' => $limit, 'attributesToHighlight' => ['title', 'summary'],
            'attributesToCrop' => ['summary'], 'cropLength' => 24,
        ] + $hl);

        $convRes = $c->index(SearchIndexer::CONVERSATIONS)->search($q, [
            'limit' => $limit, 'filter' => $filter,
            'attributesToHighlight' => ['subject', 'body'],
            'attributesToCrop' => ['body'], 'cropLength' => 26,
        ] + $hl);

        $compRes = $c->index(SearchIndexer::COMPANIES)->search($q, [
            'limit' => $limit, 'attributesToHighlight' => ['name', 'subtitle', 'fields'],
            'attributesToCrop' => ['fields'], 'cropLength' => 22,
        ] + $hl);

        // Dokumente = hochgeladene Kunden-Dokumente + Mail-Anhänge, nach Relevanz gemischt.
        $docRes = $c->index(SearchIndexer::DOCUMENTS)->search($q, [
            'limit' => $limit, 'showRankingScore' => true,
            'attributesToHighlight' => ['name', 'companyName', 'body'],
            'attributesToCrop' => ['body'], 'cropLength' => 26,
        ] + $hl);
        $attRes = $c->index(SearchIndexer::ATTACHMENTS)->search($q, [
            'limit' => $limit, 'filter' => $filter, 'showRankingScore' => true,
            'attributesToHighlight' => ['name', 'body'],
            'attributesToCrop' => ['body'], 'cropLength' => 26,
        ] + $hl);

        $docItems = array_map(fn ($h) => [
            'kind' => 'document', 'score' => $h['_rankingScore'] ?? 0,
            'id' => $h['id'], 'name' => $h['name'] ?? '', 'nameHl' => $this->full($h, 'name'),
            'type' => $h['type'] ?? null, 'companyName' => $h['companyName'] ?? null,
            'snippet' => $this->crop($h, 'body'), 'preview' => $this->previewable('', (string) ($h['name'] ?? '')),
            'pruned' => false, 'conversationId' => null,
        ], $docRes->getHits());
        $attItems = array_map(fn ($h) => [
            'kind' => 'attachment', 'score' => $h['_rankingScore'] ?? 0,
            'id' => $h['id'], 'name' => $h['name'] ?? '', 'nameHl' => $this->full($h, 'name'),
            'type' => null, 'companyName' => $h['customerName'] ?? null,
            'snippet' => $this->crop($h, 'body'), 'preview' => (bool) ($h['preview'] ?? false),
            'pruned' => (bool) ($h['pruned'] ?? false), 'conversationId' => $h['conversationId'] ?? null,
        ], $attRes->getHits());
        $documents = array_merge($docItems, $attItems);
        usort($documents, fn ($a, $b) => $b['score'] <=> $a['score']);
        $documents = array_map(function ($x) { unset($x['score']); return $x; }, \array_slice($documents, 0, $limit));

        return [
            'tasks' => array_map(fn ($h) => [
                'id' => $h['id'], 'title' => $h['title'] ?? '', 'titleHl' => $this->full($h, 'title'),
                'status' => $h['status'] ?? null, 'conversationId' => $h['conversationId'] ?? null,
                'snippet' => $this->crop($h, 'summary'),
            ], $taskRes->getHits()),
            'conversations' => array_map(fn ($h) => [
                'id' => $h['id'], 'subject' => $h['subject'] ?? '', 'subjectHl' => $this->full($h, 'subject'),
                'from' => $h['from'] ?? null, 'hasTask' => $h['hasTask'] ?? false,
                'date' => $h['date'] ?? null, 'mailbox' => $h['mailboxName'] ?? null,
                'snippet' => $this->crop($h, 'body'),
            ], $convRes->getHits()),
            'companies' => array_map(fn ($h) => [
                'id' => $h['id'], 'name' => $h['name'] ?? '', 'nameHl' => $this->full($h, 'name'),
                'subtitle' => $h['subtitle'] ?? null, 'snippet' => $this->crop($h, 'fields'),
            ], $compRes->getHits()),
            'documents' => $documents,
            'counts' => [
                'tasks' => $taskRes->getEstimatedTotalHits(),
                'conversations' => $convRes->getEstimatedTotalHits(),
                'companies' => $compRes->getEstimatedTotalHits(),
                'documents' => $docRes->getEstimatedTotalHits() + $attRes->getEstimatedTotalHits(),
            ],
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
            return '';
        }

        return mb_substr($s, 0, 240);
    }

    private function previewable(string $type, string $name): bool
    {
        $t = mb_strtolower($type);

        return str_contains($t, 'pdf') || str_starts_with($t, 'image/')
            || str_contains($t, 'word') || str_contains($t, 'sheet') || str_contains($t, 'excel')
            || str_contains($t, 'presentation') || str_contains($t, 'opendocument')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|png|jpe?g|gif|webp)$/i', $name);
    }

    /** Postgres-Fallback (Teilstring). @return array<string,mixed> */
    private function fallback(string $q, ?User $user, int $limit): array
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
            ->setParameter('q', $like)->orderBy('t.createdAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
        $taskOut = array_map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'titleHl' => $t->title, 'status' => $t->status, 'conversationId' => $t->conversation?->id, 'snippet' => ''], $tasks);

        $convs = $this->em->createQueryBuilder()
            ->select('DISTINCT c')->from(Conversation::class, 'c')->leftJoin('c.emails', 'e')
            ->where('LOWER(c.subject) LIKE :q OR LOWER(c.customerName) LIKE :q OR LOWER(c.customerEmail) LIKE :q OR LOWER(e.bodyText) LIKE :q')
            ->setParameter('q', $like)->setMaxResults(80)
            ->getQuery()->getResult();
        $convOut = [];
        foreach ($convs as $c) {
            if (!$this->maySee($c, $user, isset($taskConvIds[$c->id]))) {
                continue;
            }
            $convOut[] = ['id' => $c->id, 'subject' => $c->subject, 'subjectHl' => $c->subject, 'from' => $c->customerName ?: $c->customerEmail, 'hasTask' => isset($taskConvIds[$c->id]), 'date' => Tz::fmt($c->lastMessageAt), 'mailbox' => $c->mailbox?->name, 'snippet' => ''];
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
            if (\count($companyOut) >= $limit) {
                break;
            }
        }

        $docs = $this->em->createQueryBuilder()
            ->select('d')->from(Document::class, 'd')
            ->where('d.path IS NOT NULL')
            ->andWhere('LOWER(d.name) LIKE :q OR LOWER(d.extractedText) LIKE :q')
            ->setParameter('q', $like)->setMaxResults($limit)
            ->getQuery()->getResult();
        $docOut = array_map(fn (Document $d) => [
            'kind' => 'document', 'id' => $d->id, 'name' => $d->name, 'nameHl' => $d->name, 'type' => $d->type,
            'companyName' => $d->company?->name, 'snippet' => '', 'preview' => $this->previewable((string) $d->contentType, $d->name),
            'pruned' => false, 'conversationId' => null,
        ], $docs);

        // Mail-Anhänge (sichtbarkeits-gefiltert)
        $atts = $this->em->createQueryBuilder()
            ->select('a')->from(Attachment::class, 'a')->join('a.email', 'e')->join('e.conversation', 'c')
            ->where('LOWER(a.filename) LIKE :q OR LOWER(a.extractedText) LIKE :q')
            ->setParameter('q', $like)->setMaxResults(80)
            ->getQuery()->getResult();
        foreach ($atts as $a) {
            $cv = $a->email?->conversation;
            if (!$cv || !$this->maySee($cv, $user, isset($taskConvIds[$cv->id]))) {
                continue;
            }
            $docOut[] = [
                'kind' => 'attachment', 'id' => $a->id, 'name' => $a->filename, 'nameHl' => $a->filename, 'type' => null,
                'companyName' => $cv->customerName ?: $cv->customerEmail, 'snippet' => '',
                'preview' => $this->previewable((string) $a->contentType, $a->filename),
                'pruned' => null !== $a->prunedAt, 'conversationId' => $cv->id,
            ];
            if (\count($docOut) >= $limit) {
                break;
            }
        }

        return ['tasks' => $taskOut, 'conversations' => $convOut, 'companies' => $companyOut, 'documents' => $docOut,
            'counts' => ['tasks' => \count($taskOut), 'conversations' => \count($convOut), 'companies' => \count($companyOut), 'documents' => \count($docOut)]];
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
