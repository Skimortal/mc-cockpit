<?php

namespace App\Util;

/**
 * Zeitzonen-Helfer für die Ausgabe. In der DB liegen alle Zeitstempel in UTC;
 * Nutzer sitzen in Österreich, daher wird für die Anzeige nach Europe/Vienna konvertiert.
 */
final class Tz
{
    public const ZONE = 'Europe/Vienna';

    /** Formatiert einen UTC-Zeitstempel in österreichischer Lokalzeit (mit Sommer-/Winterzeit). */
    public static function fmt(?\DateTimeInterface $dt, string $format = 'Y-m-d H:i'): ?string
    {
        if (null === $dt) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($dt)
            ->setTimezone(new \DateTimeZone(self::ZONE))
            ->format($format);
    }
}
