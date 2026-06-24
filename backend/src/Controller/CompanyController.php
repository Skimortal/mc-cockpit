<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\Mailbox;
use App\Entity\Task;
use App\Service\Attachment\AttachmentConverter;
use App\Service\Attachment\DocumentExtractor;
use App\Service\Search\SearchIndexer;
use App\Util\Tz;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Kunden/Firmen verwalten – flexibel (frei erweiterbare Felder, Kontakte je Abteilung, Dokumente). */
class CompanyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentExtractor $extractor,
        private readonly AttachmentConverter $converter,
        private readonly SearchIndexer $search,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/api/companies', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $crit = [];
        if ($kind = $request->query->get('kind')) {
            $crit['kind'] = $kind;
        }
        $companies = $this->em->getRepository(Company::class)->findBy($crit, ['name' => 'ASC']);

        return $this->json(array_map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'subtitle' => $c->subtitle,
            'kind' => $c->kind,
            'tags' => $c->tags,
            'contactCount' => \count($this->em->getRepository(Contact::class)->findBy(['company' => $c])),
            'docCount' => \count($this->em->getRepository(Document::class)->findBy(['company' => $c])),
        ], $companies));
    }

    #[Route('/api/companies/{id}', methods: ['GET'])]
    public function show(Company $c): JsonResponse
    {
        return $this->json($this->detail($c));
    }

    #[Route('/api/companies', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        if (empty($d['name'])) {
            return $this->json(['error' => 'Name fehlt.'], 400);
        }
        $c = new Company();
        $c->name = $d['name'];
        $c->kind = $d['kind'] ?? 'hersteller';
        $c->subtitle = $d['subtitle'] ?? null;
        $this->em->persist($c);
        $this->em->flush();
        $this->search->indexCompany($c);

        return $this->json($this->detail($c), 201);
    }

    #[Route('/api/companies/{id}', methods: ['PATCH'])]
    public function update(Company $c, Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        foreach (['name', 'subtitle', 'kind', 'note'] as $f) {
            if (\array_key_exists($f, $d)) {
                $c->$f = $d[$f];
            }
        }
        if (\array_key_exists('tags', $d) && \is_array($d['tags'])) {
            $c->tags = array_values($d['tags']);
        }
        if (\array_key_exists('customFields', $d) && \is_array($d['customFields'])) {
            $c->customFields = array_values($d['customFields']);
        }
        $this->em->flush();
        $this->search->indexCompany($c); // damit Name/Tags/Felder sofort durchsuchbar bleiben

        return $this->json($this->detail($c));
    }

    #[Route('/api/companies/{id}/contacts', methods: ['POST'])]
    public function addContact(Company $c, Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        $k = new Contact();
        $k->company = $c;
        $k->firstName = (string) ($d['name'] ?? '');
        $k->department = $d['department'] ?? null;
        $k->email = $d['email'] ?? null;
        $k->phone = $d['phone'] ?? null;
        $this->em->persist($k);
        $this->em->flush();

        return $this->json($this->detail($c), 201);
    }

    #[Route('/api/companies/{id}/documents', methods: ['POST'])]
    public function addDocument(Company $c, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Keine Datei hochgeladen.'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['error' => 'Upload fehlgeschlagen.'], 400);
        }
        if ($file->getSize() > 25 * 1024 * 1024) {
            return $this->json(['error' => 'Datei zu groß (max. 25 MB).'], 413);
        }

        $orig = (string) $file->getClientOriginalName();
        $name = trim((string) $request->request->get('name')) ?: $orig ?: 'Dokument';
        $ext = strtoupper((string) (pathinfo($orig, \PATHINFO_EXTENSION) ?: ''));

        $doc = new Document();
        $doc->company = $c;
        $doc->name = mb_substr($name, 0, 200);
        $doc->type = $ext ? mb_substr($ext, 0, 20) : 'DATEI';
        $doc->contentType = mb_substr((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'), 0, 150);
        $doc->size = (int) $file->getSize();
        $this->em->persist($doc);
        $this->em->flush(); // braucht die ID für den Pfad

        $safe = preg_replace('/[^\w.\- ]+/u', '_', $orig) ?: 'datei';
        $safe = mb_substr(trim($safe), 0, 180);
        $dir = $this->projectDir.'/var/documents/'.$c->id;
        @mkdir($dir, 0775, true);
        $rel = $c->id.'/'.$doc->id.'_'.$safe;
        $file->move($dir, $doc->id.'_'.$safe);
        $doc->path = $rel;
        $this->em->flush();

        // Text extrahieren (Tika) + im documents-Index ablegen.
        if ($this->extractor->isExtractable($doc)) {
            $this->extractor->extract($doc);
        } else {
            $this->search->indexDocument($doc);
        }

        return $this->json($this->detail($c), 201);
    }

    #[Route('/api/documents/{id}/download', methods: ['GET'])]
    public function downloadDocument(Document $doc): Response
    {
        $path = null !== $doc->path ? $this->projectDir.'/var/documents/'.$doc->path : '';
        if ('' === $path || !is_file($path)) {
            return new Response('Datei nicht vorhanden.', 404);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $doc->contentType ?: 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $doc->name);

        return $response;
    }

    #[Route('/api/documents/{id}/preview', methods: ['GET'])]
    public function previewDocument(Document $doc): Response
    {
        $src = null !== $doc->path ? $this->projectDir.'/var/documents/'.$doc->path : '';
        if ('' === $src || !is_file($src)) {
            return new Response('Datei nicht vorhanden.', 404);
        }
        if ($this->converter->isImageType((string) $doc->contentType)) {
            $r = new BinaryFileResponse($src);
            $r->headers->set('Content-Type', $doc->contentType ?: 'application/octet-stream');
            $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $doc->name);

            return $r;
        }
        $pdf = $this->converter->pdfPathFor($src, $doc->name, (string) $doc->contentType);
        if (!$pdf) {
            return new Response('Keine Vorschau verfügbar.', 415);
        }
        $r = new BinaryFileResponse($pdf);
        $r->headers->set('Content-Type', 'application/pdf');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.pdf');

        return $r;
    }

    /** Kleines Vorschaubild (erste Seite / skaliert). 204 = kein Thumbnail möglich. */
    #[Route('/api/documents/{id}/thumb', methods: ['GET'])]
    public function thumbDocument(Document $doc): Response
    {
        $src = null !== $doc->path ? $this->projectDir.'/var/documents/'.$doc->path : '';
        if ('' === $src || !is_file($src)) {
            return new Response('', 204);
        }
        $thumb = $this->converter->thumbPathFor($src, $doc->name, (string) $doc->contentType);
        if (!$thumb) {
            return new Response('', 204);
        }
        $r = new BinaryFileResponse($thumb);
        $r->headers->set('Content-Type', 'image/png');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'thumb.png');

        return $r;
    }

    #[Route('/api/companies/{id}', methods: ['DELETE'])]
    public function deleteCompany(Company $c): JsonResponse
    {
        foreach ($this->em->getRepository(Contact::class)->findBy(['company' => $c]) as $k) {
            $this->em->remove($k);
        }
        $docIds = [];
        foreach ($this->em->getRepository(Document::class)->findBy(['company' => $c]) as $doc) {
            $this->removeDocumentFile($doc);
            if (null !== $doc->id) {
                $docIds[] = $doc->id;
            }
            $this->em->remove($doc);
        }
        $this->em->remove($c);
        $this->em->flush();
        foreach ($docIds as $did) {
            $this->search->removeDoc(SearchIndexer::DOCUMENTS, $did);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/api/contacts/{id}', methods: ['PATCH'])]
    public function updateContact(Contact $k, Request $request): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        if (isset($d['name'])) {
            $k->firstName = (string) $d['name'];
            $k->lastName = '';
        }
        if (\array_key_exists('department', $d)) {
            $k->department = $d['department'] ?: null;
        }
        if (\array_key_exists('email', $d)) {
            $k->email = $d['email'] ?: null;
        }
        if (\array_key_exists('phone', $d)) {
            $k->phone = $d['phone'] ?: null;
        }
        $this->em->flush();

        return $this->json($this->detail($k->company));
    }

    #[Route('/api/contacts/{id}', methods: ['DELETE'])]
    public function deleteContact(Contact $k): JsonResponse
    {
        $c = $k->company;
        $this->em->remove($k);
        $this->em->flush();

        return $this->json($c ? $this->detail($c) : ['ok' => true]);
    }

    #[Route('/api/documents/{id}', methods: ['DELETE'])]
    public function deleteDocument(Document $doc): JsonResponse
    {
        $c = $doc->company;
        $id = $doc->id;
        $this->removeDocumentFile($doc);
        $this->em->remove($doc);
        $this->em->flush();
        if (null !== $id) {
            $this->search->removeDoc(SearchIndexer::DOCUMENTS, $id);
        }

        return $this->json($c ? $this->detail($c) : ['ok' => true]);
    }

    /** Löscht die Datei (und ggf. gecachte PDF-Vorschau) eines Dokuments von der Platte. */
    private function removeDocumentFile(Document $doc): void
    {
        if (null === $doc->path) {
            return;
        }
        $path = $this->projectDir.'/var/documents/'.$doc->path;
        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($path.'.preview.pdf')) {
            @unlink($path.'.preview.pdf');
        }
    }

    /** @return array<string,mixed> */
    private function detail(Company $c): array
    {
        $contactEntities = $this->em->getRepository(Contact::class)->findBy(['company' => $c], ['id' => 'ASC']);
        $contacts = array_map(fn (Contact $k) => [
            'id' => $k->id,
            'department' => $k->department ?: 'Sonstige',
            'name' => trim($k->firstName.' '.$k->lastName),
            'email' => $k->email,
            'phone' => $k->phone,
        ], $contactEntities);

        $docs = array_map(fn (Document $doc) => [
            'id' => $doc->id,
            'name' => $doc->name,
            'type' => $doc->type,
            'date' => $doc->date->format('Y-m-d'),
            'size' => $doc->size,
            'hasFile' => null !== $doc->path,
            'preview' => null !== $doc->path
                && ($this->converter->isImageType((string) $doc->contentType)
                    || $this->converter->canPreviewAsPdfType((string) $doc->contentType, $doc->name)),
        ], $this->em->getRepository(Document::class)->findBy(['company' => $c], ['id' => 'DESC']));

        // Verknüpfte Aufgaben (direkt am Kunden).
        $tasks = $this->em->getRepository(Task::class)->findBy(['company' => $c], ['createdAt' => 'DESC']);
        $taskOut = array_map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'type' => $t->type,
            'status' => $t->status,
            'conversationId' => $t->conversation?->id,
            'dueDate' => $t->dueDate?->format('Y-m-d'),
            'overdue' => $t->dueDate && 'done' !== $t->status && $t->dueDate < new \DateTimeImmutable('today'),
            'assignee' => $t->assignee ? (trim($t->assignee->getFirstName().' '.$t->assignee->getLastName()) ?: $t->assignee->getEmail()) : null,
        ], $tasks);

        // Verknüpfte Mails: aus den Aufgaben + über die E-Mail-Adressen der Kontakte.
        $convMap = [];
        foreach ($tasks as $t) {
            if ($t->conversation?->id) {
                $convMap[$t->conversation->id] = $t->conversation;
            }
        }
        // Eigene Postfach-Adressen ausschließen: ein Kontakt mit z. B. office@hdv-stojakovic.at
        // würde sonst ALLE Konversationen dieses Postfachs an die Firma hängen.
        $own = [];
        foreach ($this->em->getRepository(Mailbox::class)->findAll() as $mb) {
            if ($mb->email) {
                $own[mb_strtolower($mb->email)] = true;
            }
        }
        $emails = array_values(array_unique(array_filter(array_map(
            fn (Contact $k) => $k->email ? mb_strtolower($k->email) : null,
            $contactEntities
        ), fn (?string $e) => $e && !isset($own[$e]))));
        if ($emails) {
            $rows = $this->em->createQueryBuilder()
                ->select('cv')->from(Conversation::class, 'cv')
                ->where('LOWER(cv.customerEmail) IN (:emails)')
                ->setParameter('emails', $emails)->setMaxResults(50)
                ->getQuery()->getResult();
            foreach ($rows as $cv) {
                $convMap[$cv->id] = $cv;
            }
        }
        $convOut = [];
        foreach ($convMap as $cv) {
            $eff = $cv->emails->last() ? $cv->emails->last()->occurredAt : ($cv->lastMessageAt ?? $cv->createdAt);
            $convOut[] = [
                'id' => $cv->id,
                'subject' => $cv->subject,
                'from' => $cv->customerName ?: $cv->customerEmail,
                'date' => Tz::fmt($eff),
                '_ts' => $eff?->getTimestamp() ?? 0,
            ];
        }
        usort($convOut, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);
        $convOut = array_map(fn ($r) => ['id' => $r['id'], 'subject' => $r['subject'], 'from' => $r['from'], 'date' => $r['date']], $convOut);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'subtitle' => $c->subtitle,
            'kind' => $c->kind,
            'tags' => $c->tags,
            'note' => $c->note,
            'fields' => $c->customFields,
            'contacts' => $contacts,
            'documents' => $docs,
            'tasks' => $taskOut,
            'conversations' => $convOut,
        ];
    }
}
