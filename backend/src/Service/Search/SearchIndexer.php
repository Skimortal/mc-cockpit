<?php

namespace App\Service\Search;

use App\Entity\Attachment;
use App\Entity\Company;
use App\Entity\Conversation;
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
        foreach ([self::TASKS, self::CONVERSATIONS, self::COMPANIES] as $i) {
            try {
                $this->client->createIndex($i, ['primaryKey' => 'id']);
            } catch (\Throwable) {
            }
        }
        $this->client->index(self::CONVERSATIONS)->updateFilterableAttributes(['mailboxScope', 'ownerId', 'hasTask']);
        $this->client->index(self::CONVERSATIONS)->updateSearchableAttributes(['subject', 'customer', 'body']);
        $this->client->index(self::TASKS)->updateSearchableAttributes(['title', 'summary']);
        $this->client->index(self::COMPANIES)->updateSearchableAttributes(['name', 'subtitle', 'tags', 'fields']);
        // Tippfehler schon ab kurzen Wörtern tolerieren (Default: 5/9).
        foreach ([self::TASKS, self::CONVERSATIONS, self::COMPANIES] as $i) {
            $this->client->index($i)->updateTypoTolerance(['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]]);
        }
    }

    public function reindexAll(): void
    {
        $this->ensureSettings();
        $this->client->index(self::TASKS)->addDocuments(array_map([$this, 'taskDoc'], $this->em->getRepository(Task::class)->findAll()), 'id');
        $this->client->index(self::CONVERSATIONS)->addDocuments(array_map([$this, 'convDoc'], $this->em->getRepository(Conversation::class)->findAll()), 'id');
        $this->client->index(self::COMPANIES)->addDocuments(array_map([$this, 'companyDoc'], $this->em->getRepository(Company::class)->findAll()), 'id');
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
    }

    public function indexCompany(Company $c): void
    {
        $this->safe(fn () => $this->client->index(self::COMPANIES)->addDocuments([$this->companyDoc($c)], 'id'));
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

        return [
            'id' => $c->id,
            'subject' => $c->subject,
            'customer' => trim(($c->customerName ?? '').' '.($c->customerEmail ?? '')),
            'from' => $c->customerName ?: $c->customerEmail,
            'body' => mb_substr(trim($body), 0, 30000),
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

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Suchindex-Fehler dürfen die App nie blockieren.
        }
    }
}
