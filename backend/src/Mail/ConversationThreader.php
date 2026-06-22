<?php

namespace App\Mail;

use App\Entity\Attachment;
use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ordnet eine abgerufene Nachricht einer bestehenden Konversation zu (über
 * In-Reply-To / References / Betreff+Absender) oder legt eine neue an.
 * Idempotent: bereits gespeicherte Message-IDs werden übersprungen.
 */
final class ConversationThreader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
    ) {
    }

    /** @return Email|null  null = Duplikat (bereits vorhanden) */
    public function ingest(Mailbox $mailbox, ParsedMessage $msg): ?Email
    {
        $emailRepo = $this->em->getRepository(Email::class);

        if ($existing = $emailRepo->findOneBy(['messageId' => $msg->messageId])) {
            // Schon verarbeitet – aber Anhänge ggf. nachrüsten (z. B. nach manuellem Abruf).
            $this->saveAttachments($existing, $msg->attachments);

            return null;
        }

        $conversation = $this->findConversation($mailbox, $msg) ?? $this->newConversation($mailbox, $msg);

        $email = new Email();
        $email->conversation = $conversation;
        $email->direction = $this->isOwn($msg->fromAddress) ? 'out' : 'in';
        $email->fromAddress = $msg->fromAddress;
        $email->toAddress = $msg->toAddress;
        $email->ccAddress = $msg->ccAddress;
        $email->subject = $msg->subject;
        $email->bodyText = $msg->bodyText;
        $email->bodyHtml = $msg->bodyHtml;
        $email->messageId = $msg->messageId;
        $email->inReplyTo = $msg->inReplyTo;
        $email->refs = $msg->references;
        $email->occurredAt = $msg->date;
        $this->em->persist($email);

        $conversation->lastMessageAt = $msg->date;
        $conversation->status = 'open';
        // Kunde = externe Partei. Falls noch nicht gesetzt oder bisher faelschlich das
        // eigene Postfach gespeichert wurde, aus dieser Nachricht neu bestimmen.
        if (null === $conversation->customerEmail || $this->isOwn($conversation->customerEmail)) {
            [$cEmail, $cName] = $this->resolveCustomer($msg);
            if ($cEmail) {
                $conversation->customerEmail = $cEmail;
                $conversation->customerName = $cName;
            }
        }

        $this->em->flush();

        $this->saveAttachments($email, $msg->attachments);

        return $email;
    }

    /**
     * Speichert die (in Temp-Dateien vorliegenden) Anhänge unter var/attachments/<emailId>/.
     * Idempotent: hat die Mail schon Anhänge, werden nur die Temp-Dateien aufgeräumt.
     *
     * @param list<array{name:string,mime:string,tmp:string,size:int}> $atts
     */
    private function saveAttachments(Email $email, array $atts): void
    {
        if (empty($atts) || !$email->id) {
            foreach ($atts as $a) {
                @unlink($a['tmp']);
            }

            return;
        }

        $repo = $this->em->getRepository(Attachment::class);
        if ($repo->count(['email' => $email]) > 0) {
            foreach ($atts as $a) {
                @unlink($a['tmp']);
            }

            return;
        }

        $dir = $this->projectDir.'/var/attachments/'.$email->id;
        @mkdir($dir, 0775, true);

        $i = 0;
        $saved = 0;
        foreach ($atts as $a) {
            if (!is_file($a['tmp'])) {
                continue;
            }
            $safe = preg_replace('/[^\w.\- ]+/u', '_', $a['name']) ?: 'datei';
            $safe = mb_substr(trim($safe), 0, 180);
            $rel = $email->id.'/'.$i.'_'.$safe;
            $dest = $this->projectDir.'/var/attachments/'.$rel;

            if (@rename($a['tmp'], $dest) || (@copy($a['tmp'], $dest) && @unlink($a['tmp']))) {
                $att = new Attachment();
                $att->email = $email;
                $att->filename = mb_substr($a['name'], 0, 255);
                $att->contentType = mb_substr($a['mime'], 0, 150);
                $att->size = $a['size'];
                $att->path = $rel;
                $this->em->persist($att);
                ++$saved;
            }
            ++$i;
        }

        if ($saved > 0) {
            $this->em->flush();
        }
    }

    /** @var array<string,true>|null */
    private ?array $ownAddrCache = null;

    /** Eigene Postfach-Adressen (lowercase) – nie als „Kunde" verwenden. */
    private function isOwn(?string $addr): bool
    {
        if (!$addr) {
            return false;
        }
        if (null === $this->ownAddrCache) {
            $this->ownAddrCache = [];
            foreach ($this->em->getRepository(Mailbox::class)->findAll() as $mb) {
                if ($mb->email) {
                    $this->ownAddrCache[mb_strtolower($mb->email)] = true;
                }
            }
        }

        return isset($this->ownAddrCache[mb_strtolower($addr)]);
    }

    /**
     * Bestimmt die externe Gegenpartei einer Nachricht: bei eingehenden Mails der
     * Absender, bei ausgehenden (Absender = eigenes Postfach) der Empfänger.
     *
     * @return array{0:?string,1:?string} [email, name]
     */
    private function resolveCustomer(ParsedMessage $msg): array
    {
        if ($msg->fromAddress && !$this->isOwn($msg->fromAddress)) {
            return [$msg->fromAddress, $msg->fromName];
        }
        if ($msg->toAddress && !$this->isOwn($msg->toAddress)) {
            return [$msg->toAddress, null];
        }

        return [$msg->fromAddress, $msg->fromName];
    }

    private function findConversation(Mailbox $mailbox, ParsedMessage $msg): ?Conversation
    {
        $emailRepo = $this->em->getRepository(Email::class);

        // 1) In-Reply-To zeigt direkt auf eine bekannte Nachricht
        if ($msg->inReplyTo) {
            $parent = $emailRepo->findOneBy(['messageId' => $msg->inReplyTo]);
            if ($parent && $parent->conversation) {
                return $parent->conversation;
            }
        }

        // 2) References-Kette: irgendeine bekannte Message-ID
        if ($msg->references) {
            foreach ($this->parseReferences($msg->references) as $ref) {
                $parent = $emailRepo->findOneBy(['messageId' => $ref]);
                if ($parent && $parent->conversation) {
                    return $parent->conversation;
                }
            }
        }

        // 3) Fallback: offene Konversation mit gleichem (normalisiertem) Betreff + Absender
        $normalized = $this->normalizeSubject($msg->subject);
        [$custEmail] = $this->resolveCustomer($msg);
        if ('' !== $normalized && $custEmail) {
            $candidates = $this->em->getRepository(Conversation::class)->findBy([
                'mailbox' => $mailbox,
                'customerEmail' => $custEmail,
            ]);
            foreach ($candidates as $c) {
                if ($this->normalizeSubject($c->subject) === $normalized) {
                    return $c;
                }
            }
        }

        return null;
    }

    private function newConversation(Mailbox $mailbox, ParsedMessage $msg): Conversation
    {
        $c = new Conversation();
        $c->mailbox = $mailbox;
        $c->subject = $msg->subject;
        [$cEmail, $cName] = $this->resolveCustomer($msg);
        $c->customerEmail = $cEmail;
        $c->customerName = $cName;
        $c->rootMessageId = $msg->messageId;
        $c->status = 'open';
        $c->lastMessageAt = $msg->date;
        $this->em->persist($c);

        return $c;
    }

    /** @return string[] */
    private function parseReferences(string $references): array
    {
        preg_match_all('/<[^>]+>/', $references, $m);

        return $m[0] ?? [];
    }

    private function normalizeSubject(string $subject): string
    {
        $s = $subject;
        // führende Re:/AW:/Fwd:/WG: (auch mehrfach) entfernen
        $s = preg_replace('/^(\s*(re|aw|fwd|fw|wg)\s*:\s*)+/iu', '', $s) ?? $s;

        return mb_strtolower(trim($s));
    }
}
