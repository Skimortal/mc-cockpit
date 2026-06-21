<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\TaskFile;
use App\Entity\User;
use App\Service\Attachment\AttachmentConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Datei-Anhänge an Aufgaben (z. B. ZIP) – hochladen, herunterladen, löschen. */
class TaskFileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentConverter $converter,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/api/tasks/{id}/files', methods: ['POST'])]
    public function upload(Task $task, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Keine Datei hochgeladen.'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['error' => 'Upload fehlgeschlagen.'], 400);
        }
        if ($file->getSize() > 50 * 1024 * 1024) {
            return $this->json(['error' => 'Datei zu groß (max. 50 MB).'], 413);
        }

        $orig = (string) $file->getClientOriginalName();
        $tf = new TaskFile();
        $tf->task = $task;
        $tf->filename = mb_substr($orig ?: 'datei', 0, 255);
        $tf->contentType = mb_substr((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'), 0, 150);
        $tf->size = (int) $file->getSize();
        $tf->uploadedBy = $user ? (trim($user->getFirstName().' '.$user->getLastName()) ?: $user->getEmail()) : null;
        $this->em->persist($tf);
        $this->em->flush(); // ID für den Pfad

        $safe = preg_replace('/[^\w.\- ]+/u', '_', $orig) ?: 'datei';
        $safe = mb_substr(trim($safe), 0, 180);
        $dir = $this->projectDir.'/var/task-files/'.$task->id;
        @mkdir($dir, 0775, true);
        $rel = $task->id.'/'.$tf->id.'_'.$safe;
        $file->move($dir, $tf->id.'_'.$safe);
        $tf->path = $rel;
        $this->em->flush();

        return $this->json($this->arr($tf), 201);
    }

    #[Route('/api/files/{id}/download', methods: ['GET'])]
    public function download(TaskFile $f): Response
    {
        $path = $this->projectDir.'/var/task-files/'.$f->path;
        if (!is_file($path)) {
            return new Response('Datei nicht vorhanden.', 404);
        }
        $r = new BinaryFileResponse($path);
        $r->headers->set('Content-Type', $f->contentType ?: 'application/octet-stream');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $f->filename);

        return $r;
    }

    #[Route('/api/files/{id}/preview', methods: ['GET'])]
    public function preview(TaskFile $f): Response
    {
        $src = $this->projectDir.'/var/task-files/'.$f->path;
        if (!is_file($src)) {
            return new Response('Datei nicht vorhanden.', 404);
        }
        if ($this->converter->isImageType((string) $f->contentType)) {
            $r = new BinaryFileResponse($src);
            $r->headers->set('Content-Type', $f->contentType ?: 'application/octet-stream');
            $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $f->filename);

            return $r;
        }
        $pdf = $this->converter->pdfPathFor($src, $f->filename, (string) $f->contentType);
        if (!$pdf) {
            return new Response('Keine Vorschau verfügbar.', 415);
        }
        $r = new BinaryFileResponse($pdf);
        $r->headers->set('Content-Type', 'application/pdf');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.pdf');

        return $r;
    }

    #[Route('/api/files/{id}', methods: ['DELETE'])]
    public function delete(TaskFile $f): JsonResponse
    {
        $path = $this->projectDir.'/var/task-files/'.$f->path;
        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($path.'.preview.pdf')) {
            @unlink($path.'.preview.pdf');
        }
        $this->em->remove($f);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function arr(TaskFile $f): array
    {
        $type = mb_strtolower((string) $f->contentType);
        $preview = str_contains($type, 'pdf') || str_starts_with($type, 'image/')
            || str_contains($type, 'word') || str_contains($type, 'sheet') || str_contains($type, 'excel')
            || str_contains($type, 'presentation') || str_contains($type, 'opendocument')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|png|jpe?g|gif|webp)$/i', $f->filename);

        return [
            'id' => $f->id,
            'name' => $f->filename,
            'size' => $f->size,
            'ext' => strtoupper((string) (pathinfo($f->filename, \PATHINFO_EXTENSION) ?: 'DATEI')),
            'uploadedBy' => $f->uploadedBy,
            'date' => $f->createdAt->format('Y-m-d'),
            'preview' => $preview,
        ];
    }
}
