<?php

namespace App\Service\Lpn;

use App\Entity\Email;
use App\Service\Llm\LlmClient;

/**
 * Liest aus einer Lieferanten-Mail (Radenko/Mladegs) die LPN-Daten:
 * PO-Nummer(n) + je PO die MHD-Gruppen mit Palettenzahl. Die Mengen/das Ziel
 * stehen NICHT in der Mail, sondern später im Manhattan-Portal.
 */
final class LpnParser
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * Liest die LPN-Daten aus den jüngsten eingehenden Nachrichten eines Threads.
     * Radenko schickt entweder EINE Bestellung über mehrere Mails verteilt (PO in
     * der einen, MHD/Paletten in der anderen) ODER mehrere eigenständige Bestellungen
     * je in einer eigenen Mail. Beide Fälle liefern jede PO als eigenen Eintrag.
     *
     * @param list<Email> $emails eingehende Mails des Threads, chronologisch (alt → neu)
     *
     * @return array{pos: list<array<string,mixed>>, actionable: bool, warning: ?string}
     */
    public function parse(array $emails): array
    {
        $data = $this->llm->extract($this->system(), $this->userText($emails), $this->schema(), null, 1200);

        $pos = [];
        foreach ((array) ($data['pos'] ?? []) as $p) {
            $po = preg_replace('/\D+/', '', (string) ($p['po'] ?? ''));
            if ('' === $po) {
                continue;
            }
            $groups = [];
            $total = 0;
            foreach ((array) ($p['groups'] ?? []) as $g) {
                $d = $this->date((string) ($g['mhd'] ?? ''));
                $pallets = (int) ($g['pallets'] ?? 0);
                if (!$d || $pallets <= 0) {
                    continue;
                }
                $groups[] = [
                    'mhdIso' => $d->format('Y-m-d'),
                    'verfallsd' => $d->format('d.m.y'), // Manhattan-Feld „Verfallsd."  -> 22.06.28
                    'charge' => $d->format('dmY'),      // Manhattan-Feld „Charge" + Dateiname -> 22062028
                    'pallets' => $pallets,
                ];
                $total += $pallets;
            }
            if (!$groups) {
                continue;
            }
            $pos[] = [
                'po' => $po,
                'groups' => $groups,
                'totalPallets' => $total,
                'note' => isset($p['note']) && '' !== trim((string) $p['note']) ? (string) $p['note'] : null,
                // Kompakter Code für den Browser-Helfer (Baustein 2): Ziel/Menge holt er live aus dem Portal.
                'code' => json_encode([
                    'v' => 1,
                    'po' => $po,
                    'groups' => array_map(static fn ($g) => ['mhd' => $g['mhdIso'], 'pallets' => $g['pallets']], $groups),
                ], \JSON_UNESCAPED_SLASHES),
            ];
        }

        return [
            'pos' => $pos,
            'actionable' => (bool) ($data['actionable'] ?? !empty($pos)),
            'warning' => isset($data['warning']) && '' !== trim((string) $data['warning']) ? (string) $data['warning'] : null,
        ];
    }

    private function date(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        foreach (['!Y-m-d', '!d.m.Y', '!d.m.y', '!d/m/Y', '!d/m/y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d instanceof \DateTimeImmutable) {
                return $d;
            }
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<Email> $emails */
    private function userText(array $emails): string
    {
        // Jüngste zuerst, höchstens die letzten 4 eingehenden Nachrichten.
        $recent = \array_slice(array_reverse($emails), 0, 4);
        $subject = $recent[0]->subject ?? '(kein Betreff)';

        $blocks = [];
        foreach ($recent as $i => $e) {
            $body = $e->bodyText ?: strip_tags((string) $e->bodyHtml);
            // Zitierte Vorgängermails abschneiden – Radenkos eigentliche Anweisung steht oben.
            foreach (["\nFrom:", "\nVon:", "\n-----Original", "\nAm ", "\nSent:", "\nGesendet:"] as $marker) {
                $cut = mb_strpos($body, $marker);
                if (false !== $cut && $cut > 30) {
                    $body = mb_substr($body, 0, $cut);
                }
            }
            $body = trim($body);
            if ('' === $body) {
                continue;
            }
            $label = 0 === $i ? 'NEUESTE Nachricht' : 'frühere Nachricht';
            $blocks[] = sprintf('--- %s (%s) ---'."\n".'%s', $label, $e->occurredAt->format('Y-m-d'), mb_substr($body, 0, 1500));
        }

        return sprintf("Betreff: %s\n\n%s", $subject, implode("\n\n", $blocks));
    }

    private function system(): string
    {
        return <<<'TXT'
            Du extrahierst aus den jüngsten Nachrichten eines E-Mail-Threads mit einem Lieferanten
            (oft Radenko Marinković / Mladegs Pak) die Daten zum Erstellen von LPN-Etiketten
            (Manhattan-Lagerportal). Er schreibt meist kurz auf Bosnisch/Serbisch oder Deutsch.

            Ein Thread kann MEHRERE Bestellungen enthalten. Gib JEDE eigenständige PO als eigenen
            Eintrag in „pos" zurück – auch wenn sie in einer älteren Nachricht steht. Verwirf keine
            frühere Bestellung, nur weil eine neuere existiert.
            Unterscheide dabei zwei Fälle:
            - EINE Bestellung über mehrere Mails verteilt: Eine Nachricht nennt die PO, eine andere
              (ohne PO) die MHD/Paletten dazu → als EINE PO zusammenführen.
            - MEHRERE eigenständige Bestellungen: Jede Nachricht bringt ihre eigene PO mit ihren
              eigenen MHD/Paletten → jede als SEPARATE PO ausgeben.
            Im Zweifel, ob MHD/Paletten ohne eigene PO zur PO einer anderen Nachricht gehören:
            der zeitlich nächstgelegenen PO zuordnen.

            Du brauchst pro Bestellung (PO):
            - po: die PO-/Bestellnummer. Sie steht im FLIESSTEXT einer Nachricht (lange Zahl,
              meist ~10 Stellen, oft mit 45… beginnend). NICHT aus dem Betreff nehmen – der ist oft
              eine alte PO aus zitierten Antworten. Gibt der Text keine PO her: po = null.
            - groups: eine oder mehrere MHD-Gruppen. Jede Gruppe hat:
                - mhd: das Mindesthaltbarkeitsdatum als ISO YYYY-MM-DD.
                - pallets: Anzahl Paletten dieser MHD (Wörter: paleta/palete/paletten/pal).
              Beispiele:
                „02.06.2028. svih 11 paleta."  -> 1 Gruppe: mhd 2028-06-02, pallets 11.
                „5 paleta 02.06.28 i 6 paleta 10.07.28" -> 2 Gruppen.
              Meist sind es insgesamt 11 Paletten.

            Regeln:
            - Nur Daten verwenden, die wirklich dastehen. Nichts erfinden.
            - actionable = false, wenn die Mail KEINE konkreten LPN-Daten (PO + MHD + Paletten) enthält
              (z. B. reine Rückfrage, Weiterleitung ohne Angaben).
            - warning: kurzer deutscher Hinweis, falls etwas unklar/unvollständig ist (z. B. „keine PO im
              Text gefunden", „Palettenzahl fehlt"), sonst null.
            Antworte ausschließlich im vorgegebenen JSON-Schema.
            TXT;
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'pos' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'po' => ['type' => ['string', 'null']],
                            'groups' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'mhd' => ['type' => 'string'],
                                        'pallets' => ['type' => 'integer'],
                                    ],
                                    'required' => ['mhd', 'pallets'],
                                ],
                            ],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['po', 'groups'],
                    ],
                ],
                'actionable' => ['type' => 'boolean'],
                'warning' => ['type' => ['string', 'null']],
            ],
            'required' => ['pos', 'actionable'],
        ];
    }
}
