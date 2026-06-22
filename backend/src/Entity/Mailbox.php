<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Konfiguration eines Postfachs (IMAP-Abruf + SMTP-Versand). */
#[ORM\Entity]
class Mailbox
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = '';

    #[ORM\Column(length: 180)]
    public string $email = '';

    /** scope: global (Team-Postfach, alle sehen es) | personal (nur der owner). */
    #[ORM\Column(length: 10, options: ['default' => 'global'])]
    public string $scope = 'global';

    /** Eigentümer bei scope=personal (null bei global). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $owner = null;

    #[ORM\Column(length: 180)]
    public string $imapHost = '';

    #[ORM\Column]
    public int $imapPort = 993;

    /** ssl|tls|none */
    #[ORM\Column(length: 10)]
    public string $imapEncryption = 'ssl';

    #[ORM\Column(length: 180)]
    public string $smtpHost = '';

    #[ORM\Column]
    public int $smtpPort = 587;

    /** tls|ssl|none */
    #[ORM\Column(length: 10)]
    public string $smtpEncryption = 'tls';

    #[ORM\Column(length: 180)]
    public string $username = '';

    /** Postfach-Passwort (nur serverseitig; nicht über die API ausliefern – später verschlüsseln). */
    #[ORM\Column(length: 255)]
    public string $password = '';

    #[ORM\Column]
    public bool $active = true;

    /** Anhang-Dateien nach so vielen Monaten von der Platte entfernen (Text bleibt durchsuchbar). 0 = nie. */
    #[ORM\Column(options: ['default' => 12])]
    public int $attachmentRetentionMonths = 12;

    /** Ganze Mail nach so vielen Monaten löschen (Original bleibt im mbox-Archiv). 0 = nie. */
    #[ORM\Column(options: ['default' => 0])]
    public int $mailRetentionMonths = 0;

    /** Standard-Signatur dieses Postfachs (pro Nachricht überschreibbar). */
    #[ORM\ManyToOne(targetEntity: Signature::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Signature $defaultSignature = null;
}
