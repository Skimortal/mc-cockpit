<?php

namespace App\Service\Search;

use App\Entity\Attachment;
use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;

/** Synchronisiert Aufgaben, Konversationen und Kunden nach Meilisearch. */
final class SearchIndexer
{
    public const TASKS = 'tasks';
    public const CONVERSATIONS = 'conversations';
    public const COMPANIES = 'companies';
    public const DOCUMENTS = 'documents';
    public const ATTACHMENTS = 'attachments';

    private Client $client;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(MEILI_HOST)%')] string $host,
        #[Autowire('%env(MEILI_KEY)%')] string $key,
    ) {
        $psr = new Psr18Client();
        $this->client = new Client($host, $key, $psr, $psr, [], $psr);
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function ensureSettings(): void
    {
        foreach ([self::TASKS, self::CONVERSATIONS, self::COMPANIES, self::DOCUMENTS, self::ATTACHMENTS] as $i) {
            try {
                $this->client->createIndex($i, ['primaryKey' => 'id']);
            } catch (\Throwable) {
            }
        }
        $this->client->index(self::CONVERSATIONS)->updateFilterableAttributes(['mailboxScope', 'ownerId', 'hasTask']);
        $this->client->index(self::CONVERSATIONS)->updateSearchableAttributes(['subject', 'customer', 'body']);
        $this->client->index(self::TASKS)->updateSearchableAttributes(['title', 'summary']);
        $this->client->index(self::COMPANIES)->updateSearchableAttributes(['name', 'subtitle', 'tags', 'fields']);
        $this->client->index(self::DOCUMENTS)->updateSearchableAttributes(['name', 'companyName', 'body']);
        $this->client->index(self::ATTACHMENTS)->updateFilterableAttributes(['mailboxScope', 'ownerId', 'hasTask']);
        $this->client->index(self::ATTACHMENTS)->updateSearchableAttributes(['name', 'body']);
        // Tippfehler schon ab kurzen Wörtern tolerieren (Default: 5/9).
        foreach ([self::TASKS, self::CONVERSATIONS, self::COMPANIES, self::DOCUMENTS, self::ATTACHMENTS] as $i) {
            $this->client->index($i)->updateTypoTolerance(['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]]);
        }
    }

    public function reindexAll(): void
    {
        $this->ensureSettings();
        $this->client->index(self::TASKS)->addDocuments(array_map([$this, 'taskDoc'], $this->em->getRepository(Task::class)->findAll()), 'id');
        $this->client->index(self::CONVERSATIONS)->addDocuments(array_map([$this, 'convDoc'], $this->em->getRepository(Conversation::class)->findAll()), 'id');
        $this->client->index(self::COMPANIES)->addDocuments(array_map([$this, 'companyDoc'], $this->em->getRepository(Company::class)->findAll()), 'id');
        $docs = array_filter($this->em->getRepository(Document::class)->findAll(), fn (Document $d) => null !== $d->path);
        if ($docs) {
            $this->client->index(self::DOCUMENTS)->addDocuments(array_map([$this, 'documentDoc'], array_values($docs)), 'id');
        }
        $atts = array_filter($this->em->getRepository(Attachment::class)->findAll(), fn (Attachment $a) => null !== $a->id && $a->email?->conversation && !$this->isIndexJunk($a));
        $this->safe(fn () => $this->client->index(self::ATTACHMENTS)->deleteAllDocuments());
        if ($atts) {
            $this->client->index(self::ATTACHMENTS)->addDocuments(array_map([$this, 'attachmentDoc'], array_values($atts)), 'id');
        }
    }

    /** Icons/Signatur-Schnipsel u. Ä. sind keine sinnvollen Dokument-Treffer. */
    private function isIndexJunk(Attachment $a): bool
    {
        return str_contains((string) $a->contentType, 'icon')
            || (bool) preg_match('/\.ico$/i', $a->filename);
    }

    public function indexTask(Task $t): void
    {
        $this->safe(fn () => $this->client->index(self::TASKS)->addDocuments([$this->taskDoc($t)], 'id'));
        if ($t->conversation) {
            $this->indexConversation($t->conversation);
        }
    }

    public function indexConversation(Conversation $c): void
    {
        $this->safe(fn () => $this->client->index(self::CONVERSATIONS)->addDocuments([$this->convDoc($c)], 'id'));
        // Anhänge der Konversation als eigene Suchtreffer mitführen.
        $docs = [];
        $attRepo = $this->em->getRepository(Attachment::class);
        foreach ($c->emails as $e) {
            foreach ($attRepo->findBy(['email' => $e]) as $a) {
                if (null !== $a->id && !$this->isIndexJunk($a)) {
                    $docs[] = $this->attachmentDoc($a);
                }
            }
        }
        if ($docs) {
            $this->safe(fn () => $this->client->index(self::ATTACHMENTS)->addDocuments($docs, 'id'));
        }
    }

    public function indexCompany(Company $c): void
    {
        $this->safe(fn () => $this->client->index(self::COMPANIES)->addDocuments([$this->companyDoc($c)], 'id'));
    }

    public function indexDocument(Document $d): void
    {
        if (null === $d->id) {
            return;
        }
        $this->safe(fn () => $this->client->index(self::DOCUMENTS)->addDocuments([$this->documentDoc($d)], 'id'));
    }

    public function removeDoc(string $index, int $id): void
    {
        $this->safe(fn () => $this->client->index($index)->deleteDocument($id));
    }

    /** @return array<string,mixed> */
    public function taskDoc(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'summary' => $t->aiSummary,
            'status' => $t->status,
            'conversationId' => $t->conversation?->id,
        ];
    }

    /** @return array<string,mixed> */
    public function convDoc(Conversation $c): array
    {
        $body = '';
        $attRepo = $this->em->getRepository(Attachment::class);
        foreach ($c->emails as $e) {
            $body .= ' '.($e->bodyText ?: strip_tags((string) $e->bodyHtml));
            foreach ($attRepo->findBy(['email' => $e]) as $a) {
                $body .= ' '.$a->filename;
                if ($a->extractedText && '[' !== ($a->extractedText[0] ?? '')) {
                    $body .= ' '.$a->extractedText;
                }
            }
        }
        $mb = $c->mailbox;
        $hasTask = $this->em->getRepository(Task::class)->count(['conversation' => $c]) > 0;

        $eff = $c->emails->last() ? $c->emails->last()->occurredAt : ($c->lastMessageAt ?? $c->createdAt);

        return [
            'id' => $c->id,
            'subject' => $c->subject,
            'customer' => trim(($c->customerName ?? '').' '.($c->customerEmail ?? '')),
            'from' => $c->customerName ?: $c->customerEmail,
            'body' => mb_substr(trim($body), 0, 30000),
            'date' => $eff?->format('Y-m-d H:i'),
            'mailboxId' => $mb?->id ?? 0,
            'mailboxName' => $mb?->name ?? '',
            'mailboxScope' => $mb?->scope ?? 'none',
            'ownerId' => $mb?->owner?->getId() ?? 0,
            'hasTask' => $hasTask,
        ];
    }

    /** @return array<string,mixed> */
    public function attachmentDoc(Attachment $a): array
    {
        $c = $a->email?->conversation;
        $mb = $c?->mailbox;
        $hasTask = $c && $this->em->getRepository(Task::class)->count(['conversation' => $c]) > 0;
        $body = (string) $a->extractedText;
        if ('' !== $body && '[' === ($body[0] ?? '')) {
            $body = '';
        }
        $type = mb_strtolower((string) $a->contentType);
        $preview = str_contains($type, 'pdf') || str_starts_with($type, 'image/')
            || str_contains($type, 'word') || str_contains($type, 'sheet') || str_contains($type, 'excel')
            || str_contains($type, 'presentation') || str_contains($type, 'opendocument')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|png|jpe?g|gif|webp)$/i', $a->filename);

        return [
            'id' => $a->id,
            'name' => $a->filename,
            'body' => mb_substr(trim($body), 0, 30000),
            'conversationId' => $c?->id,
            'customerName' => $c?->customerName ?: $c?->customerEmail,
            'date' => $a->email?->occurredAt?->format('Y-m-d') ?? $a->createdAt->format('Y-m-d'),
            'preview' => $preview,
            'pruned' => null !== $a->prunedAt,
            'mailboxScope' => $mb?->scope ?? 'none',
            'ownerId' => $mb?->owner?->getId() ?? 0,
            'hasTask' => $hasTask,
        ];
    }

    /** @return array<string,mixed> */
    public function companyDoc(Company $c): array
    {
        $fields = '';
        foreach ($c->customFields as $f) {
            $fields .= ' '.($f['label'] ?? '').' '.($f['value'] ?? '');
        }

        return [
            'id' => $c->id,
            'name' => $c->name,
            'subtitle' => $c->subtitle,
            'tags' => implode(' ', $c->tags),
            'fields' => trim($fields),
        ];
    }

    /** @return array<string,mixed> */
    public function documentDoc(Document $d): array
    {
        $body = (string) $d->extractedText;
        // Platzhalter-Markierungen ([nicht verfügbar] etc.) nicht in den Suchindex aufnehmen.
        if ('' !== $body && '[' === ($body[0] ?? '')) {
            $body = '';
        }

        return [
            'id' => $d->id,
            'name' => $d->name,
            'type' => $d->type,
            'companyId' => $d->company?->id,
            'companyName' => $d->company?->name ?? '',
            'body' => mb_substr(trim($body), 0, 30000),
            'date' => $d->date->format('Y-m-d'),
        ];
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Suchindex-Fehler dürfen die App nie blockieren.
        }
    }
}
