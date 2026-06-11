<?php

namespace App\Service\Attachment;

use App\Entity\Attachment;
use App\Service\Search\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Liest Anhang-Texte per Apache Tika aus und macht sie über die Konversation durchsuchbar. */
final class AttachmentExtractor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly SearchIndexer $search,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(TIKA_URL)%')] private readonly string $tikaUrl = '',
    ) {
    }

    public function isExtractable(Attachment $a): bool
    {
        $t = (string) $a->contentType;

        return str_contains($t, 'pdf') || str_contains($t, 'word') || str_contains($t, 'opendocument')
            || str_contains($t, 'excel') || str_contains($t, 'spreadsheet')
            || str_contains($t, 'powerpoint') || str_contains($t, 'presentation')
            || str_starts_with($t, 'text/')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|txt|csv|rtf)$/i', $a->filename);
    }

    /** Extrahiert den Text und indexiert die Konversation neu. Markiert immer als verarbeitet. */
    public function extract(Attachment $a): bool
    {
        $path = $this->projectDir.'/var/attachments/'.$a->path;
        if ('' === $this->tikaUrl || !is_file($path)) {
            $a->extractedText = '[nicht verfügbar]';
            $this->em->flush();

            return false;
        }

        try {
            $resp = $this->http->request('PUT', rtrim($this->tikaUrl, '/').'/tika', [
                'headers' => ['Accept' => 'text/plain', 'Content-Type' => $a->contentType ?: 'application/octet-stream'],
                'body' => fopen($path, 'r'),
                'timeout' => 120,
            ]);
            $text = 200 === $resp->getStatusCode() ? $resp->getContent(false) : '';
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $a->extractedText = '' === $text ? '[leer]' : mb_substr($text, 0, 100000);
            $this->em->flush();

            if ($a->email?->conversation) {
                $this->search->indexConversation($a->email->conversation);
            }

            return true;
        } catch (\Throwable) {
            $a->extractedText = '[fehler]';
            $this->em->flush();

            return false;
        }
    }
}
