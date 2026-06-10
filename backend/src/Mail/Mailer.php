<?php

namespace App\Mail;

use App\Entity\Email;
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

    private function replySubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ('' === $s) {
            return 'Re: (kein Betreff)';
        }

        return preg_match('/^\s*(re|aw)\s*:/iu', $s) ? $s : 'Re: '.$s;
    }
}
