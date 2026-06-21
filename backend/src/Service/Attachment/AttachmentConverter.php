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
        return $this->isImageType((string) $a->contentType);
    }

    public function isImageType(string $contentType): bool
    {
        return str_starts_with($contentType, 'image/');
    }

    /** Konvertierbar zu PDF-Vorschau? (PDF direkt, Office via Gotenberg) */
    public function canPreviewAsPdf(Attachment $a): bool
    {
        return $this->canPreviewAsPdfType((string) $a->contentType, $a->filename);
    }

    public function canPreviewAsPdfType(string $contentType, string $filename): bool
    {
        return str_contains($contentType, 'pdf')
            || str_contains($contentType, 'word') || str_contains($contentType, 'opendocument')
            || str_contains($contentType, 'excel') || str_contains($contentType, 'spreadsheet')
            || str_contains($contentType, 'powerpoint') || str_contains($contentType, 'presentation')
            || (bool) preg_match('/\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf)$/i', $filename);
    }

    /** Liefert den Pfad zu einer PDF-Fassung (Original bei PDF, sonst konvertiert + gecacht) oder null. */
    public function pdfPath(Attachment $a): ?string
    {
        return $this->pdfPathFor($this->srcPath($a), $a->filename, (string) $a->contentType);
    }

    /**
     * Liefert ein gecachtes Thumbnail-PNG (erste Seite / skaliertes Bild) oder null.
     * Bilder werden per GD skaliert, PDF/Office per Gotenberg→PDF und pdftoppm gerastert.
     */
    public function thumbPathFor(string $src, string $filename, string $contentType): ?string
    {
        if (!is_file($src)) {
            return null;
        }
        $cache = $src.'.thumb.png';
        if (is_file($cache)) {
            return $cache;
        }
        if ($this->isImageType($contentType) || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $filename)) {
            return $this->imageThumb($src, $cache);
        }
        $pdf = $this->pdfPathFor($src, $filename, $contentType);
        if (!$pdf) {
            return null;
        }

        return $this->pdfThumb($pdf, $cache);
    }

    private function imageThumb(string $src, string $cache): ?string
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $data = @file_get_contents($src);
            if (false === $data) {
                return null;
            }
            $img = @imagecreatefromstring($data);
            if (!$img) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $max = 480;
            $scale = min(1.0, $max / max(1, max($w, $h)));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $thumb = imagescale($img, $nw, $nh);
            imagepng($thumb ?: $img, $cache, 6);
            imagedestroy($img);
            if ($thumb) {
                imagedestroy($thumb);
            }

            return is_file($cache) ? $cache : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pdfThumb(string $pdf, string $cache): ?string
    {
        // pdftoppm -singlefile hängt ".png" an den Prefix an -> Prefix ohne Endung übergeben.
        $prefix = preg_replace('/\.png$/', '', $cache);
        $cmd = sprintf('pdftoppm -png -singlefile -f 1 -l 1 -scale-to 480 %s %s 2>/dev/null',
            escapeshellarg($pdf), escapeshellarg((string) $prefix));
        @exec($cmd);

        return is_file($cache) ? $cache : null;
    }

    /**
     * Generische PDF-Vorschau für eine beliebige Datei (Anhang oder Firmen-Dokument).
     * Original bei PDF, sonst per Gotenberg konvertiert und neben der Datei gecacht.
     */
    public function pdfPathFor(string $src, string $filename, string $contentType): ?string
    {
        if (!is_file($src)) {
            return null;
        }
        if (str_contains($contentType, 'pdf') || preg_match('/\.pdf$/i', $filename)) {
            return $src;
        }
        if ('' === $this->gotenbergUrl || !$this->canPreviewAsPdfType($contentType, $filename)) {
            return null;
        }

        $cached = $src.'.preview.pdf';
        if (is_file($cached)) {
            return $cached;
        }

        try {
            $form = new FormDataPart(['files' => DataPart::fromPath($src, $filename)]);
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
