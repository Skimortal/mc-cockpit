<?php

namespace App\Service\Attachment;

use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Wandelt Office-Dokumente per Gotenberg (LibreOffice) in PDF für die Vorschau um. Ergebnis wird gecacht. */
final class AttachmentConverter
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(GOTENBERG_URL)%')] private readonly string $gotenbergUrl = '',
    ) {
    }

    private function srcPath(Attachment $a): string
    {
        return $this->projectDir.'/var/attachments/'.$a->path;
    }

    public function isImage(Attachment $a): bool
    {
        return str_starts_with((string) $a->contentType, 'image/');
    }

    /** Konvertierbar zu PDF-Vorschau? (PDF direkt, Office via Gotenberg) */
    public function canPreviewAsPdf(Attachment $a): bool
    {
        $t = (string) $a->contentType;

        return str_contains($t, 'pdf')
            || str_contains($t, 'word') || str_contains($t, 'opendocument')
            || str_contains($t, 'excel') || str_contains($t, 'spreadsheet')
            || str_contains($t, 'powerpoint') || str_contains($t, 'presentation')
            || (bool) preg_match('/\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf)$/i', $a->filename);
    }

    /** Liefert den Pfad zu einer PDF-Fassung (Original bei PDF, sonst konvertiert + gecacht) oder null. */
    public function pdfPath(Attachment $a): ?string
    {
        $src = $this->srcPath($a);
        if (!is_file($src)) {
            return null;
        }
        if (str_contains((string) $a->contentType, 'pdf') || preg_match('/\.pdf$/i', $a->filename)) {
            return $src;
        }
        if ('' === $this->gotenbergUrl || !$this->canPreviewAsPdf($a)) {
            return null;
        }

        $cached = $src.'.preview.pdf';
        if (is_file($cached)) {
            return $cached;
        }

        try {
            $form = new FormDataPart(['files' => DataPart::fromPath($src, $a->filename)]);
            $resp = $this->http->request('POST', rtrim($this->gotenbergUrl, '/').'/forms/libreoffice/convert', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 180,
            ]);
            if (200 !== $resp->getStatusCode()) {
                return null;
            }
            file_put_contents($cached, $resp->getContent());

            return is_file($cached) ? $cached : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
