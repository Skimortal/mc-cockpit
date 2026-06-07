<?php

namespace App\Mail;

use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Mailbox;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ordnet eine abgerufene Nachricht einer bestehenden Konversation zu (über
 * In-Reply-To / References / Betreff+Absender) oder legt eine neue an.
 * Idempotent: bereits gespeicherte Message-IDs werden übersprungen.
 */
final class ConversationThreader
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return Email|null  null = Duplikat (bereits vorhanden) */
    public function ingest(Mailbox $mailbox, ParsedMessage $msg): ?Email
    {
        $emailRepo = $this->em->getRepository(Email::class);

        if ($emailRepo->findOneBy(['messageId' => $msg->messageId])) {
            return null; // schon verarbeitet
        }

        $conversation = $this->findConversation($mailbox, $msg) ?? $this->newConversation($mailbox, $msg);

        $email = new Email();
        $email->conversation = $conversation;
        $email->direction = 'in';
        $email->fromAddress = $msg->fromAddress;
        $email->toAddress = $msg->toAddress;
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
        if (null === $conversation->customerEmail && $msg->fromAddress) {
            $conversation->customerEmail = $msg->fromAddress;
            $conversation->customerName = $msg->fromName;
        }

        $this->em->flush();

        return $email;
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
        if ('' !== $normalized && $msg->fromAddress) {
            $candidates = $this->em->getRepository(Conversation::class)->findBy([
                'mailbox' => $mailbox,
                'customerEmail' => $msg->fromAddress,
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
        $c->customerEmail = $msg->fromAddress;
        $c->customerName = $msg->fromName;
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
