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

        // Anhänge auf die Platte (Temp) sichern -> wenig Speicherverbrauch.
        $attachments = [];
        try {
            $i = 0;
            $tmpDir = sys_get_temp_dir().'/cockpit-att';
            foreach ($message->getAttachments() as $att) {
                $name = $this->str($att->getName());
                $mime = (string) ($att->getMimeType() ?: 'application/octet-stream');
                // Krypto-Signaturen (S/MIME, PGP) sind kein echter Inhalt -> überspringen.
                if ('smime.p7s' === $name || str_contains($mime, 'pkcs7') || str_contains($mime, 'pgp-signature')) {
                    continue;
                }
                @mkdir($tmpDir, 0700, true);
                $tmp = $tmpDir.'/'.bin2hex(random_bytes(8)).'.bin';
                $att->save($tmpDir.'/', basename($tmp));
                if (!is_file($tmp)) {
                    continue;
                }
                $size = (int) filesize($tmp);
                if ($size > 25 * 1024 * 1024) { // 25 MB Limit
                    @unlink($tmp);
                    continue;
                }
                $attachments[] = [
                    'name' => $name ?: ('datei-'.$i),
                    'mime' => $mime,
                    'tmp' => $tmp,
                    'size' => $size,
                ];
                ++$i;
            }
        } catch (\Throwable $e) {
            $this->logger->error('IMAP attachment read failed', ['mailbox' => '', 'error' => $e->getMessage()]);
        }

        return new ParsedMessage(
            messageId: $messageId,
            inReplyTo: $this->nullStr($message->getInReplyTo()),
            references: $this->nullStr($message->getReferences()),
            fromAddress: $from?->mail,
            fromName: self::mimeDecode($this->str($from?->personal)) ?: null,
            toAddress: $to?->mail,
            subject: self::mimeDecode($this->str($message->getSubject())),
            bodyText: $message->getTextBody() ?: null,
            bodyHtml: $message->getHTMLBody() ?: null,
            date: $date ?? new \DateTimeImmutable(),
            attachments: $attachments,
        );
    }

    /** Dekodiert RFC-2047 encoded-words (=?utf-8?Q?…?=) zu UTF-8. Robust gegen kaputte/teilweise Header. */
    public static function mimeDecode(string $s): string
    {
        if ('' === $s || !str_contains($s, '=?')) {
            return $s;
        }
        $d = @iconv_mime_decode($s, \ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (\is_string($d) && '' !== trim($d) && !str_contains($d, '=?')) {
            return $d;
        }
        $d2 = @mb_decode_mimeheader($s);

        return ('' !== (string) $d2) ? $d2 : $s;
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
