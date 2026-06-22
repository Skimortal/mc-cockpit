<?php

namespace App\Controller;

use App\Entity\LlmUsage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** KI-Nutzung/Kosten des laufenden Monats. */
class UsageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(float:LLM_MONTHLY_BUDGET)%')] private readonly float $budgetUsd = 0,
    ) {
    }

    #[Route('/api/llm-usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $row = $this->em->createQuery(
            'SELECT COALESCE(SUM(u.costMicros),0) AS micros, COUNT(u.id) AS calls, COALESCE(SUM(u.inputTokens),0) AS intok, COALESCE(SUM(u.outputTokens),0) AS outtok FROM App\Entity\LlmUsage u WHERE u.createdAt >= :m'
        )->setParameter('m', $start)->getSingleResult();

        $spent = ((int) $row['micros']) / 1_000_000;

        return $this->json([
            'monthSpentUsd' => round($spent, 2),
            'budgetUsd' => $this->budgetUsd,
            'percent' => $this->budgetUsd > 0 ? (int) round($spent / $this->budgetUsd * 100) : 0,
            'calls' => (int) $row['calls'],
            'inputTokens' => (int) $row['intok'],
            'outputTokens' => (int) $row['outtok'],
        ]);
    }
}
