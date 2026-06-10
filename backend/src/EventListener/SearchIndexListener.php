<?php

namespace App\EventListener;

use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Task;
use App\Service\Search\SearchIndexer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

/** Hält den Meilisearch-Index bei jeder Änderung aktuell. */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class SearchIndexListener
{
    public function __construct(private readonly SearchIndexer $indexer)
    {
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->sync($args->getObject(), false);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->sync($args->getObject(), false);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->sync($args->getObject(), true);
    }

    private function sync(object $e, bool $removed): void
    {
        if ($e instanceof Task) {
            if ($removed) {
                if ($e->id) {
                    $this->indexer->removeDoc(SearchIndexer::TASKS, $e->id);
                }
                if ($e->conversation) {
                    $this->indexer->indexConversation($e->conversation);
                }
            } else {
                $this->indexer->indexTask($e);
            }
        } elseif ($e instanceof Conversation) {
            $removed ? $this->indexer->removeDoc(SearchIndexer::CONVERSATIONS, (int) $e->id) : $this->indexer->indexConversation($e);
        } elseif ($e instanceof Email) {
            if ($e->conversation) {
                $this->indexer->indexConversation($e->conversation);
            }
        } elseif ($e instanceof Company) {
            $removed ? $this->indexer->removeDoc(SearchIndexer::COMPANIES, (int) $e->id) : $this->indexer->indexCompany($e);
        }
    }
}
