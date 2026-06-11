<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Kunden/Firmen verwalten – flexibel (frei erweiterbare Felder, Kontakte je Abteilung, Dokumente). */
class CompanyController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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
        $d = json_decode($request->getContent(), true) ?: [];
        if (empty($d['name'])) {
            return $this->json(['error' => 'Name fehlt.'], 400);
        }
        $doc = new Document();
        $doc->company = $c;
        $doc->name = $d['name'];
        $doc->type = strtoupper((string) ($d['type'] ?? 'PDF'));
        $this->em->persist($doc);
        $this->em->flush();

        return $this->json($this->detail($c), 201);
    }

    #[Route('/api/companies/{id}', methods: ['DELETE'])]
    public function deleteCompany(Company $c): JsonResponse
    {
        foreach ($this->em->getRepository(Contact::class)->findBy(['company' => $c]) as $k) {
            $this->em->remove($k);
        }
        foreach ($this->em->getRepository(Document::class)->findBy(['company' => $c]) as $doc) {
            $this->em->remove($doc);
        }
        $this->em->remove($c);
        $this->em->flush();

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
        $this->em->remove($doc);
        $this->em->flush();

        return $this->json($c ? $this->detail($c) : ['ok' => true]);
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
        ], $this->em->getRepository(Document::class)->findBy(['company' => $c], ['id' => 'DESC']));

        // Verknüpfte Aufgaben (direkt am Kunden).
        $tasks = $this->em->getRepository(Task::class)->findBy(['company' => $c], ['createdAt' => 'DESC']);
        $taskOut = array_map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
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
        $emails = array_values(array_unique(array_filter(array_map(
            fn (Contact $k) => $k->email ? mb_strtolower($k->email) : null,
            $contactEntities
        ))));
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
                'date' => $eff?->format('Y-m-d H:i'),
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
