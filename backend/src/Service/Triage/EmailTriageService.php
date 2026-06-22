<?php

namespace App\Service\Triage;

use App\Entity\Company;
use App\Entity\Email;
use App\Entity\Product;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\Tender;
use App\Entity\User;
use App\Service\Llm\LlmClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Macht aus einer eingehenden E-Mail per Claude eine strukturierte Aufgabe.
 */
final class EmailTriageService
{
    private const TASK_TYPES = ['general', 'send_sample', 'deadline', 'send_asn', 'label_manhattan', 'logistics', 'lab'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmClient $llm,
    ) {
    }

    public function triage(Email $email): ?Task
    {
        try {
            $data = $this->llm->extract(
                $this->systemPrompt(),
                $this->userText($email),
                $this->schema(),
            );
        } catch (\Throwable) {
            // z. B. Budget erreicht oder API-Fehler -> keine KI-Aufgabe (Aufrufer legt eine Basis-Aufgabe an).
            return null;
        }

        $email->triagedAt = new \DateTimeImmutable();

        if (empty($data) || empty($data['actionable'])) {
            $this->em->flush();

            return null; // FYI-Mail: keine Aufgabe
        }

        $task = new Task();
        $task->title = (string) ($data['title'] ?? '(ohne Titel)');
        $task->type = \in_array($data['taskType'] ?? '', self::TASK_TYPES, true) ? $data['taskType'] : 'general';
        $task->priority = \in_array($data['priority'] ?? '', ['low', 'normal', 'high'], true) ? $data['priority'] : 'normal';
        $task->aiSummary = $data['summary'] ?? null;
        $task->description = $data['summary'] ?? null;
        $task->conversation = $email->conversation;
        $task->sourceEmail = $email;
        $task->suggestedAssignee = $data['suggestedAssignee'] ?? null;

        if (!empty($data['deadlineIso'])) {
            try {
                $task->dueDate = new \DateTimeImmutable((string) $data['deadlineIso']);
            } catch (\Throwable) {
            }
        }

        if (!empty($data['recognizedCompany'])) {
            $task->company = $this->matchCompany((string) $data['recognizedCompany']);
        }
        if (!empty($data['recognizedTender'])) {
            $task->tender = $this->matchTender((string) $data['recognizedTender']);
        }
        if (!empty($data['suggestedAssignee'])) {
            $task->assignee = $this->matchUser((string) $data['suggestedAssignee']);
        }

        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function systemPrompt(): string
    {
        $companies = $this->names(Company::class);
        $projects = $this->names(Project::class);
        $products = $this->names(Product::class);
        $tenders = $this->names(Tender::class);
        $users = array_map(
            static fn (User $u) => trim($u->getFirstName().' '.$u->getLastName()),
            $this->em->getRepository(User::class)->findAll()
        );

        return <<<TXT
            Du bist ein Assistent der MOST Connect KG, einer Handelsvertretung, die Hersteller
            (Lebensmittel/Textil) bei europäischen Handelsketten (Aldi/Hofer, Edeka, Norma …) listet.
            Aus einer eingehenden Geschäfts-E-Mail extrahierst du eine umsetzbare AUFGABE.

            Aufgaben-Typen:
            - send_sample (Muster versenden)
            - deadline (Frist/Ausschreibung einreichen)
            - send_asn (ASN/Liefermeldung versenden)
            - label_manhattan (Etikett über Manhattan-Portal erstellen)
            - logistics (Logistik/Incoterm klären)
            - lab (Laboranalyse/Qualität)
            - general (sonstiges)

            Bekannte Firmen: {$this->joinList($companies)}
            Bekannte Projekte/Mandate: {$this->joinList($projects)}
            Bekannte Produkte: {$this->joinList($products)}
            Bekannte Ausschreibungen: {$this->joinList($tenders)}
            Team (mögliche Zuständige): {$this->joinList($users)}

            Regeln:
            - title: kurz, handlungsorientiert, Deutsch (z. B. „Muster Ketchup an Hofer senden").
            - summary: 1–2 Sätze, worum es geht.
            - recognizedCompany/recognizedTender/recognizedProduct: NUR aus den bekannten Listen,
              sonst null. suggestedAssignee: Vorname aus dem Team oder null.
            - deadlineIso: falls eine Frist genannt ist, als ISO-Datum (YYYY-MM-DD), sonst null.
            - actionable: false für reine Info/Newsletter/automatische Benachrichtigungen ohne To-do.
            Antworte ausschließlich im vorgegebenen JSON-Schema.
            TXT;
    }

    private function userText(Email $email): string
    {
        $body = $email->bodyText ?: strip_tags((string) $email->bodyHtml);
        $body = mb_substr(trim($body), 0, 4000);

        return sprintf(
            "Von: %s\nBetreff: %s\nDatum: %s\n\n%s",
            $email->fromAddress ?? '?',
            $email->subject ?? '(kein Betreff)',
            $email->occurredAt->format('Y-m-d'),
            $body
        );
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'taskType' => ['type' => 'string', 'enum' => self::TASK_TYPES],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'deadlineIso' => ['type' => ['string', 'null']],
                'recognizedCompany' => ['type' => ['string', 'null']],
                'recognizedTender' => ['type' => ['string', 'null']],
                'recognizedProduct' => ['type' => ['string', 'null']],
                'suggestedAssignee' => ['type' => ['string', 'null']],
                'actionable' => ['type' => 'boolean'],
            ],
            'required' => ['title', 'summary', 'taskType', 'priority', 'actionable'],
        ];
    }

    /** @param class-string $class @return string[] */
    private function names(string $class): array
    {
        $rows = $this->em->getRepository($class)->createQueryBuilder('e')
            ->select('e.name')->setMaxResults(100)->getQuery()->getScalarResult();

        return array_column($rows, 'name');
    }

    /** @param string[] $list */
    private function joinList(array $list): string
    {
        return $list ? implode(', ', $list) : '(noch keine)';
    }

    private function matchCompany(string $name): ?Company
    {
        return $this->em->getRepository(Company::class)->createQueryBuilder('c')
            ->where('LOWER(c.name) LIKE :n')->setParameter('n', '%'.mb_strtolower($name).'%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function matchTender(string $name): ?Tender
    {
        return $this->em->getRepository(Tender::class)->createQueryBuilder('t')
            ->where('LOWER(t.name) LIKE :n')->setParameter('n', '%'.mb_strtolower($name).'%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function matchUser(string $firstName): ?User
    {
        return $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('LOWER(u.firstName) = :n')->setParameter('n', mb_strtolower(trim($firstName)))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
