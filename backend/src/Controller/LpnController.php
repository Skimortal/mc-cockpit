<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Task;
use App\Service\Lpn\LpnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LPN-Assistent: liest aus einer Lieferanten-Mail die PO/MHD/Paletten-Daten und
 * legt daraus eine Manhattan-Aufgabe an. Das eigentliche Anlegen der LPNs passiert
 * im Browser-Helfer (Baustein 2) anhand des mitgelieferten „Manhattan-Codes".
 */
final class LpnController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LpnParser $parser,
    ) {
    }

    #[Route('/api/conversations/{id}/lpn/prepare', methods: ['POST'])]
    public function prepare(Conversation $conv): JsonResponse
    {
        $email = $this->sourceEmail($conv);
        if (!$email) {
            return new JsonResponse(['error' => 'Keine Mail in dieser Konversation.'], 422);
        }

        $result = $this->parser->parse($email);
        $company = $this->companyForEmail($conv->customerEmail);

        return new JsonResponse([
            'pos' => $result['pos'],
            'actionable' => $result['actionable'],
            'warning' => $result['warning'],
            'suggestedCompanyId' => $company?->id,
            'suggestedCompanyName' => $company?->name,
        ]);
    }

    #[Route('/api/conversations/{id}/lpn/task', methods: ['POST'])]
    public function createTask(Conversation $conv, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        $pos = \is_array($body['pos'] ?? null) ? $body['pos'] : [];
        if (!$pos) {
            return new JsonResponse(['error' => 'Keine PO-Daten übergeben.'], 422);
        }

        $companyId = (int) ($body['companyId'] ?? 0);
        $company = $companyId > 0 ? $this->em->getRepository(Company::class)->find($companyId) : null;
        $email = $this->sourceEmail($conv);

        $created = 0;
        foreach ($pos as $p) {
            $po = preg_replace('/\D+/', '', (string) ($p['po'] ?? ''));
            if ('' === $po) {
                continue;
            }
            $groups = \is_array($p['groups'] ?? null) ? $p['groups'] : [];
            $total = (int) ($p['totalPallets'] ?? array_sum(array_map(static fn ($g) => (int) ($g['pallets'] ?? 0), $groups)));
            $mhds = implode(', ', array_filter(array_map(static fn ($g) => (string) ($g['verfallsd'] ?? ''), $groups)));

            $task = new Task();
            $task->type = 'label_manhattan';
            $task->status = 'open';
            $task->priority = 'normal';
            $task->title = sprintf('LPNs erstellen – PO %s (%d Pal.%s)', $po, $total, '' !== $mhds ? ', MHD '.$mhds : '');
            $task->description = $this->describe($p);
            $task->company = $company;
            $task->conversation = $conv;
            $task->sourceEmail = $email;
            $task->tags = ['Logistik'];
            $this->em->persist($task);
            ++$created;
        }
        $this->em->flush();

        return new JsonResponse(['created' => $created], 201);
    }

    /** Lesbare Beschreibung mit den exakt einzutippenden Manhattan-Werten. */
    private function describe(array $p): string
    {
        $po = (string) ($p['po'] ?? '');
        $lines = ['LPNs im Manhattan-Portal erstellen (PO – Erstelle LPNs).', ''];
        foreach ((array) ($p['groups'] ?? []) as $i => $g) {
            $lines[] = sprintf(
                'MHD-Gruppe %d: %d Palette(n) · Verfallsd. %s · Charge %s · Dateiname %s_<Ziel>_%s.pdf',
                $i + 1,
                (int) ($g['pallets'] ?? 0),
                (string) ($g['verfallsd'] ?? ''),
                (string) ($g['charge'] ?? ''),
                $po,
                (string) ($g['charge'] ?? ''),
            );
        }
        $lines[] = '';
        $lines[] = 'Menge/LPN = „Gesamt bestellt" ÷ Gesamtpaletten (im Portal). Liefertermin = jetzt.';
        if (!empty($p['note'])) {
            $lines[] = 'Hinweis Lieferant: '.(string) $p['note'];
        }

        return implode("\n", $lines);
    }

    private function sourceEmail(Conversation $conv): ?Email
    {
        $last = null;
        $lastIn = null;
        foreach ($conv->emails as $e) {
            $last = $e;
            if ('in' === $e->direction) {
                $lastIn = $e;
            }
        }

        return $lastIn ?? $last;
    }

    private function companyForEmail(?string $email): ?Company
    {
        $email = trim((string) $email);
        if ('' === $email) {
            return null;
        }
        $contact = $this->em->getRepository(Contact::class)->createQueryBuilder('k')
            ->where('LOWER(k.email) = :e')->setParameter('e', mb_strtolower($email))
            ->andWhere('k.company IS NOT NULL')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $contact?->company;
    }
}
