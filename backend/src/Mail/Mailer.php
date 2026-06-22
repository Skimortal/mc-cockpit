<?php

namespace App\Mail;

use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Mailbox;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

/** Versendet (threaded) Antworten über das SMTP des jeweiligen Postfachs und speichert sie. */
final class Mailer
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Antwort aus einer Aufgabe – delegiert an die konversationsbasierte Variante. */
    public function sendReply(Task $task, string $body, ?string $subjectOverride = null, ?string $to = null, ?string $cc = null, ?string $signatureHtml = null, array $attachments = []): Email
    {
        if (!$task->conversation) {
            throw new \RuntimeException('Aufgabe hat keine Konversation für den Versand.');
        }

        return $this->sendConversationReply($task->conversation, $body, $subjectOverride, $to, $cc, $signatureHtml, $attachments, $task->sourceEmail);
    }

    /**
     * Antwort direkt auf eine Konversation (ohne Aufgabe). Speichert die ausgehende Mail im Thread.
     *
     * @param list<array{path:string,name:string,type:?string}> $attachments Dateien zum Anhängen
     */
    public function sendConversationReply(Conversation $conversation, string $body, ?string $subjectOverride = null, ?string $to = null, ?string $cc = null, ?string $signatureHtml = null, array $attachments = [], ?Email $source = null): Email
    {
        // Quelle = letzte eingehende Mail des Threads (für Betreff/Threading), falls nicht übergeben.
        if (!$source) {
            foreach ($conversation->emails as $e) {
                if ('in' === $e->direction) {
                    $source = $e;
                }
            }
        }
        $mailbox = $conversation->mailbox;

        if (!$mailbox) {
            throw new \RuntimeException('Konversation hat kein Postfach für den Versand.');
        }
        if ('' === $mailbox->password) {
            throw new \RuntimeException('Für das Postfach ist kein SMTP-Passwort hinterlegt.');
        }

        $own = mb_strtolower($mailbox->email);
        $toList = $this->addrs($to ?? ($source?->fromAddress ?: $conversation->customerEmail));
        $toList = array_values(array_filter($toList, fn ($a) => mb_strtolower($a) !== $own));
        if (!$toList) {
            throw new \RuntimeException('Kein Empfänger ermittelbar.');
        }
        $ccList = array_values(array_filter($this->addrs($cc), fn ($a) => mb_strtolower($a) !== $own && !\in_array(mb_strtolower($a), array_map('mb_strtolower', $toList), true)));

        $subject = $subjectOverride ?: $this->replySubject($source?->subject ?: $conversation->subject);
        $inReplyTo = $source?->messageId;
        $references = trim(($source?->refs ? $source->refs.' ' : '').($inReplyTo ?? ''));

        // Signatur: explizit übergeben, sonst die des Postfachs.
        $sig = trim((string) ($signatureHtml ?? $mailbox->defaultSignature?->html ?? ''));
        $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.5">'
            .nl2br(htmlspecialchars($body, \ENT_QUOTES, 'UTF-8')).'</div>';
        if ('' !== $sig) {
            $bodyHtml .= '<br>'.$sig;
        }
        $textBody = $body;
        if ('' !== $sig) {
            $textBody .= "\n\n".trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $sig) ?? $sig), \ENT_QUOTES, 'UTF-8'));
        }

        $implicitTls = 'ssl' === $mailbox->smtpEncryption; // 465 = implizit, 587 = STARTTLS (auto)
        $transport = new EsmtpTransport($mailbox->smtpHost, $mailbox->smtpPort, $implicitTls);
        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);
        $mailer = new SymfonyMailer($transport);

        $mime = (new MimeEmail())
            ->from(new Address($mailbox->email, $mailbox->name ?: 'MOST Connect KG'))
            ->to(...$toList)
            ->subject($subject)
            ->text($textBody)
            ->html($bodyHtml);
        if ($ccList) {
            $mime->cc(...$ccList);
        }
        foreach ($attachments as $a) {
            if (!empty($a['path']) && is_file($a['path'])) {
                $mime->attachFromPath($a['path'], $a['name'] ?? basename($a['path']), $a['type'] ?: null);
            }
        }

        $headers = $mime->getHeaders();
        if ($inReplyTo) {
            $headers->addTextHeader('In-Reply-To', $inReplyTo);
        }
        if ('' !== $references) {
            $headers->addTextHeader('References', $references);
        }

        $sent = $mailer->send($mime);

        $email = new Email();
        $email->conversation = $conversation;
        $email->direction = 'out';
        $email->fromAddress = $mailbox->email;
        $email->toAddress = implode(', ', $toList);
        $email->ccAddress = $ccList ? implode(', ', $ccList) : null;
        $email->subject = $subject;
        $email->bodyText = $textBody;
        $email->bodyHtml = $bodyHtml;
        $email->messageId = $sent?->getMessageId() ? '<'.$sent->getMessageId().'>' : null;
        $email->inReplyTo = $inReplyTo;
        $email->refs = '' !== $references ? $references : null;
        $email->occurredAt = new \DateTimeImmutable();
        $this->em->persist($email);

        $conversation->lastMessageAt = $email->occurredAt;
        $conversation->status = 'waiting';
        $this->em->flush();

        return $email;
    }

    /** Komma-/Semikolon-getrennte Adressliste -> bereinigtes Array. @return string[] */
    private function addrs(?string $s): array
    {
        if (null === $s || '' === trim($s)) {
            return [];
        }
        $parts = preg_split('/[,;]+/', $s) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ('' !== $p && str_contains($p, '@')) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Interne Benachrichtigung an den zuständigen Kollegen, dass ihm eine Aufgabe
     * zugewiesen wurde (mit Link). Versand über ein Postfach mit SMTP-Passwort.
     */
    public function sendAssignmentNotice(Task $task, string $toEmail, string $toName, ?string $actorName, string $taskUrl, int $fileCount, ?string $note = null): void
    {
        $mailbox = $this->pickSmtpMailbox($task->conversation?->mailbox);
        if (!$mailbox) {
            throw new \RuntimeException('Kein Postfach mit SMTP-Passwort für die Benachrichtigung verfügbar.');
        }

        $by = $actorName ? $actorName.' hat dir' : 'Dir wurde';
        $lines = [
            'Hallo '.($toName ?: '').',',
            '',
            $by.' eine Aufgabe zugewiesen:',
            '',
            '    '.$task->title,
        ];
        if (null !== $note && '' !== trim($note)) {
            $lines[] = '';
            $lines[] = trim($note);
        }
        if ($task->aiSummary) {
            $lines[] = '';
            $lines[] = $task->aiSummary;
        }
        // Interne Kommentare/Anweisungen mitschicken, damit der Kollege den Kontext direkt hat.
        $comments = $task->comments->toArray();
        if ($comments) {
            $lines[] = '';
            $lines[] = '--- Kommentare ---';
            foreach ($comments as $k) {
                $lines[] = sprintf('• %s: %s', $k->authorName, trim((string) $k->body));
            }
        }
        if ($fileCount > 0) {
            $lines[] = '';
            $lines[] = sprintf('📎 An der Aufgabe %s %d Datei(en) zum Download.', 1 === $fileCount ? 'hängt' : 'hängen', $fileCount);
        }
        $lines[] = '';
        $lines[] = 'Direkt öffnen: '.$taskUrl;
        $lines[] = '';
        $lines[] = '— MOST Connect Cockpit';

        $implicitTls = 'ssl' === $mailbox->smtpEncryption;
        $transport = new EsmtpTransport($mailbox->smtpHost, $mailbox->smtpPort, $implicitTls);
        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);
        $mailer = new SymfonyMailer($transport);

        $mime = (new MimeEmail())
            ->from($mailbox->email)
            ->to($toEmail)
            ->subject('Neue Aufgabe für dich: '.$task->title)
            ->text(implode("\n", $lines));

        $mailer->send($mime);
    }

    private function pickSmtpMailbox(?Mailbox $preferred): ?Mailbox
    {
        if ($preferred && '' !== $preferred->password) {
            return $preferred;
        }
        foreach ($this->em->getRepository(Mailbox::class)->findBy(['scope' => 'global']) as $m) {
            if ('' !== $m->password) {
                return $m;
            }
        }
        foreach ($this->em->getRepository(Mailbox::class)->findAll() as $m) {
            if ('' !== $m->password) {
                return $m;
            }
        }

        return null;
    }

    private function replySubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ('' === $s) {
            return 'Re: (kein Betreff)';
        }

        return preg_match('/^\s*(re|aw)\s*:/iu', $s) ? $s : 'Re: '.$s;
    }
}
