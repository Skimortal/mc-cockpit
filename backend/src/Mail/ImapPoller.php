<?php

namespace App\Mail;

use App\Entity\Mailbox;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\ClientManager;

/**
 * Ruft per IMAP (webklex/php-imap) ungelesene Nachrichten eines Postfachs ab
 * und liefert sie als normalisierte ParsedMessage-Objekte. Markiert sie als gelesen.
 */
final class ImapPoller
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return ParsedMessage[]
     */
    public function poll(Mailbox $mailbox, int $limit = 50, int $sinceDays = 14): array
    {
        $cm = new ClientManager();
        $client = $cm->make([
            'host' => $mailbox->imapHost,
            'port' => $mailbox->imapPort,
            'encryption' => $this->encryption($mailbox->imapEncryption),
            'validate_cert' => true,
            'username' => $mailbox->username,
            'password' => $mailbox->password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        $folder = $client->getFolder('INBOX');
        // Zeitfenster statt "unseen": robust gegen Seen-Flags anderer Clients (z. B. FreeScout).
        // Doppelte werden später über die Message-ID herausgefiltert (Idempotenz).
        $since = \Carbon\Carbon::now()->subDays($sinceDays);
        $messages = $folder->query()
            ->since($since)
            ->limit($limit)
            ->setFetchOrder('desc') // neueste zuerst -> bei vollen Postfächern gehen aktuelle Mails nie verloren
            ->leaveUnread()
            ->get();

        $result = [];
        foreach ($messages as $message) {
            try {
                $result[] = $this->parse($message);
            } catch (\Throwable $e) {
                $this->logger->error('IMAP parse failed', ['mailbox' => $mailbox->email, 'error' => $e->getMessage()]);
            }
        }

        $client->disconnect();

        return $result;
    }

    private function parse(object $message): ParsedMessage
    {
        $from = $message->getFrom()[0] ?? null;
        $to = $message->getTo()[0] ?? null;

        $date = null;
        try {
            $d = $message->getDate()?->first();
            if ($d) {
                $date = new \DateTimeImmutable($d->format('c'));
            }
        } catch (\Throwable) {
        }

        $messageId = $this->str($message->getMessageId());
        if ('' === $messageId) {
            // Fallback: deterministische ID aus Header-Bestandteilen
            $messageId = '<generated-'.md5($this->str($message->getSubject()).($from->mail ?? '').($date?->format('c') ?? '')).'@cockpit>';
        }

        return new ParsedMessage(
            messageId: $messageId,
            inReplyTo: $this->nullStr($message->getInReplyTo()),
            references: $this->nullStr($message->getReferences()),
            fromAddress: $from?->mail,
            fromName: $from?->personal ?: null,
            toAddress: $to?->mail,
            subject: $this->str($message->getSubject()),
            bodyText: $message->getTextBody() ?: null,
            bodyHtml: $message->getHTMLBody() ?: null,
            date: $date ?? new \DateTimeImmutable(),
        );
    }

    private function encryption(string $enc): string|false
    {
        return match ($enc) {
            'none' => false,
            default => $enc, // 'ssl' | 'tls'
        };
    }

    /** webklex-Attribute robust zu String casten. */
    private function str(mixed $attr): string
    {
        if (null === $attr) {
            return '';
        }
        if (\is_object($attr) && method_exists($attr, '__toString')) {
            return trim((string) $attr);
        }

        return trim((string) (\is_array($attr) ? ($attr[0] ?? '') : $attr));
    }

    private function nullStr(mixed $attr): ?string
    {
        $s = $this->str($attr);

        return '' === $s ? null : $s;
    }
}
