<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Conversation;
use App\Entity\Mailbox;
use App\Entity\Task;
use App\Entity\User;
use App\Mail\ConversationThreader;
use App\Mail\ImapPoller;
use App\Service\Attachment\AttachmentConverter;
use App\Service\Llm\LlmClient;
use App\Service\Triage\EmailTriageService;
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

/** Posteingang (Konversationen) + „Mail → Aufgabe" — mit Postfach-Sichtbarkeit (global/persönlich). */
class InboxController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailTriageService $triage,
        private readonly ImapPoller $poller,
        private readonly ConversationThreader $threader,
        private readonly AttachmentConverter $converter,
        private readonly LlmClient $llm,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
    ) {
    }

    /** Manueller Abruf der sichtbaren Postfächer (größeres Fenster, neueste zuerst). */
    #[Route('/api/inbox/fetch', methods: ['POST'])]
    public function fetch(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $mbId = json_decode($request->getContent(), true)['mailbox'] ?? null;
        $new = 0;
        $errors = [];
        foreach ($this->visibleMailboxes($user) as $m) {
            if ($mbId && (int) $mbId !== $m->id) {
                continue;
            }
            if ('' === $m->password) {
                continue;
            }
            try {
                foreach ($this->poller->poll($m, 150, 90) as $msg) {
                    if ($this->threader->ingest($m, $msg)) {
                        ++$new;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $m->name;
            }
        }

        return $this->json(['new' => $new, 'errors' => $errors]);
    }

    #[Route('/api/mailboxes', methods: ['GET'])]
    public function mailboxes(#[CurrentUser] ?User $user): JsonResponse
    {
        $out = [];
        foreach ($this->visibleMailboxes($user) as $m) {
            $out[] = [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'scope' => $m->scope,
                'owner' => $m->owner ? trim($m->owner->getFirstName().' '.$m->owner->getLastName()) : null,
                'mine' => $user && $m->owner && $m->owner->getId() === $user->getId(),
            ];
        }

        return $this->json($out);
    }

    #[Route('/api/inbox', methods: ['GET'])]
    public function inbox(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $visible = $this->visibleMailboxes($user);
        $visibleIds = array_map(fn (Mailbox $m) => $m->id, $visible);

        // optionaler Filter über den Postfach-Switcher
        $filter = $request->query->get('mailbox'); // ID | 'global' | 'mine' | null(=alle)
        $byConv = $this->tasksByConversation();
        $convs = $this->em->getRepository(Conversation::class)->findBy([], [], 400);

        $rows = [];
        foreach ($convs as $c) {
            $involved = $this->involvedMailboxes($c);
            // nur sichtbare beteiligte Postfächer
            $visInvolved = array_filter($involved, fn (Mailbox $m) => \in_array($m->id, $visibleIds, true));
            if (!$visInvolved) {
                continue; // nicht sichtbar für diesen User
            }
            // Filter über den Postfach-Switcher gegen die beteiligten Postfächer
            if (is_numeric($filter) && !isset($visInvolved[(int) $filter])) {
                continue;
            }
            if ('global' === $filter && !array_filter($visInvolved, fn (Mailbox $m) => 'global' === $m->scope)) {
                continue;
            }
            if ('mine' === $filter && !array_filter($visInvolved, fn (Mailbox $m) => $m->owner && $user && $m->owner->getId() === $user->getId())) {
                continue;
            }
            // Anzeige-Postfach: beim Filter das gefilterte, sonst das gepinnte (falls beteiligt) bzw. erstes beteiligtes
            if (is_numeric($filter)) {
                $mb = $visInvolved[(int) $filter];
            } elseif ($c->mailbox && isset($visInvolved[$c->mailbox->id])) {
                $mb = $c->mailbox;
            } else {
                $mb = reset($visInvolved);
            }

            // Effektives Datum = neueste Nachricht im Thread (Fallback lastMessageAt/createdAt).
            $eff = $c->emails->last() ? $c->emails->last()->occurredAt : ($c->lastMessageAt ?? $c->createdAt);
            $task = $byConv[$c->id] ?? null;
            $rows[] = [
                '_ts' => $eff->getTimestamp(),
                'data' => [
                    'id' => $c->id,
                    'from' => $c->customerName ?: $c->customerEmail,
                    'email' => $c->customerEmail,
                    'subject' => $c->subject,
                    'lastMessageAt' => $eff->format('Y-m-d H:i'),
                    'messageCount' => $c->emails->count(),
                    'state' => $task ? ('done' === $task->status ? 'erledigt' : 'aufgabe') : 'neu',
                    'taskId' => $task?->id,
                    'owner' => $task?->assignee ? trim($task->assignee->getFirstName().' '.$task->assignee->getLastName()) : null,
                    'mailboxId' => $mb->id,
                    'mailboxName' => $mb->name,
                    'mailboxScope' => $mb->scope,
                ],
            ];
        }

        // Neueste zuerst.
        usort($rows, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);

        return $this->json(array_map(fn ($r) => $r['data'], $rows));
    }

    #[Route('/api/conversations/{id}', methods: ['GET'])]
    public function conversation(Conversation $c, #[CurrentUser] ?User $user): JsonResponse
    {
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return $this->json(['error' => 'Kein Zugriff auf diese Konversation.'], 403);
        }

        $attRepo = $this->em->getRepository(Attachment::class);
        $messages = [];
        foreach ($c->emails as $e) {
            $atts = [];
            foreach ($attRepo->findBy(['email' => $e], ['id' => 'ASC']) as $a) {
                $atts[] = ['id' => $a->id, 'name' => $a->filename, 'size' => $a->size, 'type' => $a->contentType, 'pruned' => null !== $a->prunedAt];
            }
            $messages[] = [
                'dir' => $e->direction,
                'who' => 'out' === $e->direction ? 'Wir' : ($e->fromAddress ?: $c->customerName),
                'to' => $e->toAddress,
                'time' => $e->occurredAt->format('Y-m-d H:i'),
                'body' => mb_substr(trim((string) ($e->bodyText ?: strip_tags((string) $e->bodyHtml))), 0, 8000),
                'attachments' => $atts,
            ];
        }

        return $this->json([
            'id' => $c->id,
            'subject' => $c->subject,
            'customerName' => $c->customerName,
            'customerEmail' => $c->customerEmail,
            'mailboxId' => $c->mailbox?->id,
            'mailboxName' => $c->mailbox?->name,
            'taskId' => ($this->tasksByConversation()[$c->id] ?? null)?->id,
            'messages' => $messages,
        ]);
    }

    #[Route('/api/conversations/{id}/to-task', methods: ['POST'])]
    public function toTask(Conversation $c, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->maySee($c, $user, false)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }
        if ($existing = $this->tasksByConversation()[$c->id] ?? null) {
            return $this->json(['ok' => true, 'taskId' => $existing->id, 'existing' => true]);
        }

        $lastIn = null;
        foreach ($c->emails as $e) {
            if ('in' === $e->direction) {
                $lastIn = $e;
            }
        }
        if (!$lastIn) {
            return $this->json(['error' => 'Keine eingehende Mail.'], 422);
        }

        // simple = ohne KI-Triage (z. B. nur um eine Datei anzuhängen)
        $simple = (bool) (json_decode($request->getContent(), true)['simple'] ?? false);
        $task = $simple ? null : $this->triage->triage($lastIn);
        if (!$task) {
            $task = new Task();
            $task->title = $c->subject ?: '(ohne Titel)';
            $task->conversation = $c;
            $task->sourceEmail = $lastIn;
            $task->aiSummary = mb_substr(trim((string) ($lastIn->bodyText ?: strip_tags((string) $lastIn->bodyHtml))), 0, 280);
            $this->em->persist($task);
            $this->em->flush();
        }

        return $this->json(['ok' => true, 'taskId' => $task->id]);
    }

    /** KI erklärt die Konversation in wenigen Stichpunkten (ohne eine Aufgabe anzulegen). */
    #[Route('/api/conversations/{id}/explain', methods: ['POST'])]
    public function explain(Conversation $c, #[CurrentUser] ?User $user): JsonResponse
    {
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return $this->json(['error' => 'Kein Zugriff.'], 403);
        }

        $system = <<<TXT
            Du hilfst der MOST Connect KG (Handelsvertretung). Erkläre die unten stehende
            E-Mail-Konversation kurz und klar auf Deutsch:
            • Worum geht es? (1 Satz)
            • Was will der Absender / was ist die Kernaussage?
            • Was ist zu tun / nächste Schritte?
            Antworte knapp in Stichpunkten, ohne Anrede/Floskeln. Erfinde nichts, was nicht im Verlauf steht.
            TXT;

        try {
            $text = $this->llm->complete($system, $this->threadContext($c));
        } catch (\Throwable) {
            return $this->json(['error' => 'KI-Erklärung fehlgeschlagen.'], 502);
        }

        return $this->json(['explanation' => trim($text)]);
    }

    /** E-Mail-Verlauf einer Konversation als Klartext für die KI. */
    private function threadContext(Conversation $c): string
    {
        $lines = ['Betreff: '.$c->subject, ''];
        foreach ($c->emails as $e) {
            $who = 'out' === $e->direction ? 'WIR' : ($e->fromAddress ?: ($c->customerName ?? 'Kunde'));
            $lines[] = sprintf('[%s, %s] %s', $who, $e->occurredAt->format('Y-m-d H:i'), $e->subject ?? '');
            $lines[] = mb_substr(trim((string) ($e->bodyText ?: strip_tags((string) $e->bodyHtml))), 0, 3000);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** Anhang herunterladen (sichtbarkeitsgeprüft). Frontend lädt per axios mit JWT als Blob. */
    #[Route('/api/attachments/{id}/download', methods: ['GET'])]
    public function downloadAttachment(Attachment $att, #[CurrentUser] ?User $user): Response
    {
        $c = $att->email?->conversation;
        if (!$c) {
            return new Response('Nicht gefunden.', 404);
        }
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return new Response('Kein Zugriff.', 403);
        }
        if (null !== $att->prunedAt) {
            return new Response('Anhang wurde nach Ablauf der Aufbewahrungsfrist entfernt – Original im Mail-Archiv.', 410);
        }
        $path = $this->projectDir.'/var/attachments/'.$att->path;
        if (!is_file($path)) {
            return new Response('Datei nicht vorhanden.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $att->contentType ?: 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $att->filename);

        return $response;
    }

    /** Inline-Vorschau: Bilder direkt, PDFs direkt, Office-Dokumente per Gotenberg nach PDF. */
    #[Route('/api/attachments/{id}/preview', methods: ['GET'])]
    public function previewAttachment(Attachment $att, #[CurrentUser] ?User $user): Response
    {
        $c = $att->email?->conversation;
        if (!$c) {
            return new Response('Nicht gefunden.', 404);
        }
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return new Response('Kein Zugriff.', 403);
        }
        if (null !== $att->prunedAt) {
            return new Response('Anhang wurde nach Ablauf der Aufbewahrungsfrist entfernt – Original im Mail-Archiv.', 410);
        }

        if ($this->converter->isImage($att)) {
            $img = $this->projectDir.'/var/attachments/'.$att->path;
            if (!is_file($img)) {
                return new Response('Datei nicht vorhanden.', 404);
            }
            $r = new BinaryFileResponse($img);
            $r->headers->set('Content-Type', $att->contentType ?: 'application/octet-stream');
            $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $att->filename);

            return $r;
        }

        $pdf = $this->converter->pdfPath($att);
        if (!$pdf) {
            return new Response('Keine Vorschau verfügbar.', 415);
        }
        $r = new BinaryFileResponse($pdf);
        $r->headers->set('Content-Type', 'application/pdf');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.pdf');

        return $r;
    }

    /** Kleines Vorschaubild (erste Seite / skaliert). 204 = kein Thumbnail möglich. */
    #[Route('/api/attachments/{id}/thumb', methods: ['GET'])]
    public function thumbAttachment(Attachment $att, #[CurrentUser] ?User $user): Response
    {
        $c = $att->email?->conversation;
        if (!$c) {
            return new Response('', 404);
        }
        $hasTask = isset($this->tasksByConversation()[$c->id]);
        if (!$this->maySee($c, $user, $hasTask)) {
            return new Response('', 403);
        }
        if (null !== $att->prunedAt) {
            return new Response('', 204);
        }
        $thumb = $this->converter->thumbPathFor($this->projectDir.'/var/attachments/'.$att->path, $att->filename, (string) $att->contentType);
        if (!$thumb) {
            return new Response('', 204);
        }
        $r = new BinaryFileResponse($thumb);
        $r->headers->set('Content-Type', 'image/png');
        $r->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'thumb.png');

        return $r;
    }

    /** @var array<string, Mailbox>|null E-Mail (lowercase) => Postfach */
    private ?array $mbByEmail = null;

    /** @return array<string, Mailbox> */
    private function mailboxesByEmail(): array
    {
        if (null === $this->mbByEmail) {
            $this->mbByEmail = [];
            foreach ($this->em->getRepository(Mailbox::class)->findAll() as $m) {
                if ($m->email) {
                    $this->mbByEmail[mb_strtolower($m->email)] = $m;
                }
            }
        }

        return $this->mbByEmail;
    }

    /**
     * Alle Postfächer, deren Adresse in From/To/CC irgendeiner Mail des Threads vorkommt.
     * Fallback: das gepinnte Postfach. So erscheint ein office@↔support@-Thread in beiden Posteingängen.
     *
     * @return array<int, Mailbox>
     */
    private function involvedMailboxes(Conversation $c): array
    {
        $map = $this->mailboxesByEmail();
        $out = [];
        foreach ($c->emails as $e) {
            $addrs = [(string) $e->fromAddress, (string) $e->toAddress];
            foreach (preg_split('/[,;]+/', (string) $e->ccAddress) ?: [] as $cc) {
                $addrs[] = $cc;
            }
            foreach ($addrs as $a) {
                $al = mb_strtolower(trim($a));
                if ('' !== $al && isset($map[$al])) {
                    $out[$map[$al]->id] = $map[$al];
                }
            }
        }
        if (!$out && $c->mailbox) {
            $out[$c->mailbox->id] = $c->mailbox;
        }

        return $out;
    }

    /** Sichtbarkeit: ein beteiligtes Postfach ist global ODER eigenes persönliches ODER (geteilt, weil Aufgabe). */
    private function maySee(Conversation $c, ?User $user, bool $hasTask): bool
    {
        if ($hasTask) {
            return true;
        }
        foreach ($this->involvedMailboxes($c) as $mb) {
            if ('global' === $mb->scope) {
                return true;
            }
            if ($user && $mb->owner && $mb->owner->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Mailbox> globale + eigene persönliche Postfächer */
    private function visibleMailboxes(?User $user): array
    {
        $all = $this->em->getRepository(Mailbox::class)->findBy(['active' => true], ['name' => 'ASC']);

        return array_values(array_filter(
            $all,
            fn (Mailbox $m) => 'global' === $m->scope || ($user && $m->owner && $m->owner->getId() === $user->getId())
        ));
    }

    /** @return array<int, Task> Konversations-ID => Aufgabe */
    private function tasksByConversation(): array
    {
        $map = [];
        foreach ($this->em->getRepository(Task::class)->findBy([], ['id' => 'ASC']) as $t) {
            if ($t->conversation?->id) {
                $map[$t->conversation->id] = $t;
            }
        }

        return $map;
    }
}
