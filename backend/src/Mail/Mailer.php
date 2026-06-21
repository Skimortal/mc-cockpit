<?php

namespace App\Mail;

use App\Entity\Email;
use App\Entity\Mailbox;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email as MimeEmail;

/** Versendet (threaded) Antworten über das SMTP des jeweiligen Postfachs und speichert sie. */
final class Mailer
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function sendReply(Task $task, string $body, ?string $subjectOverride = null): Email
    {
        $conversation = $task->conversation;
        $source = $task->sourceEmail;
        $mailbox = $conversation?->mailbox;

        if (!$mailbox || !$conversation) {
            throw new \RuntimeException('Aufgabe hat keine Konversation/Postfach für den Versand.');
        }
        if ('' === $mailbox->password) {
            throw new \RuntimeException('Für das Postfach ist kein SMTP-Passwort hinterlegt.');
        }

        $to = $source?->fromAddress ?: $conversation->customerEmail;
        if (!$to) {
            throw new \RuntimeException('Kein Empfänger ermittelbar.');
        }

        $subject = $subjectOverride ?: $this->replySubject($source?->subject ?: $conversation->subject);
        $inReplyTo = $source?->messageId;
        $references = trim(($source?->refs ? $source->refs.' ' : '').($inReplyTo ?? ''));

        // Transport aus der Postfach-Konfiguration aufbauen.
        $implicitTls = 'ssl' === $mailbox->smtpEncryption; // 465 = implizit, 587 = STARTTLS (auto)
        $transport = new EsmtpTransport($mailbox->smtpHost, $mailbox->smtpPort, $implicitTls);
        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);
        $mailer = new SymfonyMailer($transport);

        $mime = (new MimeEmail())
            ->from($mailbox->email)
            ->to($to)
            ->subject($subject)
            ->text($body);

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
        $email->toAddress = $to;
        $email->subject = $subject;
        $email->bodyText = $body;
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
