<?php

namespace App\Service\Attachment;

use App\Entity\Document;
use App\Service\Search\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Liest Firmen-Dokument-Texte per Apache Tika aus und macht sie über den documents-Index durchsuchbar. */
final class DocumentExtractor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly SearchIndexer $search,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(TIKA_URL)%')] private readonly string $tikaUrl = '',
    ) {
    }

    public function isExtractable(Document $d): bool
    {
        $t = (string) $d->contentType;

        return str_contains($t, 'pdf') || str_contains($t, 'word') || str_contains($t, 'opendocument')
            || str_contains($t, 'excel') || str_contains($t, 'spreadsheet')
            || str_contains($t, 'powerpoint') || str_contains($t, 'presentation')
            || str_starts_with($t, 'text/')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|txt|csv|rtf)$/i', $d->name);
    }

    /** Extrahiert den Text und indexiert das Dokument neu. Markiert immer als verarbeitet. */
    public function extract(Document $d): bool
    {
        $path = null !== $d->path ? $this->projectDir.'/var/documents/'.$d->path : '';
        if ('' === $this->tikaUrl || '' === $path || !is_file($path)) {
            $d->extractedText = '[nicht verfügbar]';
            $this->em->flush();
            $this->search->indexDocument($d);

            return false;
        }

        try {
            $resp = $this->http->request('PUT', rtrim($this->tikaUrl, '/').'/tika', [
                'headers' => ['Accept' => 'text/plain', 'Content-Type' => $d->contentType ?: 'application/octet-stream'],
                'body' => fopen($path, 'r'),
                'timeout' => 120,
            ]);
            $text = 200 === $resp->getStatusCode() ? $resp->getContent(false) : '';
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $d->extractedText = '' === $text ? '[leer]' : mb_substr($text, 0, 100000);
            $this->em->flush();
            $this->search->indexDocument($d);

            return true;
        } catch (\Throwable) {
            $d->extractedText = '[fehler]';
            $this->em->flush();
            $this->search->indexDocument($d);

            return false;
        }
    }
}
