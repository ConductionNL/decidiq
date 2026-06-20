<?php
/**
 * Decidesk Participation Lifecycle Service
 *
 * Guarded lifecycle transitions and server-side deadline enforcement for
 * citizen-participation rounds (PublicConsultation + ParticipatoryBudget).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service implementing citizen-participation lifecycle rules.
 *
 * Transition legality is enforced here; CALLER-side staff authorization
 * (governance-body authority) is enforced by ParticipationController via the
 * chair/secretary RBAC guard. Phase deadlines are enforced server-side on
 * every intake/vote operation INDEPENDENT of the stored status, so a stale
 * status can never re-open a closed window.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ParticipationLifecycleService
{

    /**
     * Legal consultation status transitions (source => allowed targets).
     *
     * @var array<string, string[]>
     */
    private const CONSULTATION_TRANSITIONS = [
        'draft'             => ['open'],
        'open'              => ['closed'],
        'closed'            => ['results-published'],
        'results-published' => [],
    ];

    /**
     * Legal budget-round status transitions (source => allowed targets).
     *
     * @var array<string, string[]>
     */
    private const BUDGET_TRANSITIONS = [
        'draft'      => ['submission'],
        'submission' => ['voting'],
        'voting'     => ['tallying'],
        'tallying'   => ['closed'],
        'closed'     => [],
    ];

    /**
     * Constructor for ParticipationLifecycleService.
     *
     * @param ContainerInterface $container The DI container (lazy-loads ObjectService)
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService lazily.
     *
     * @return object The ObjectService instance
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Normalise a saved ObjectEntity (or array) to an array.
     *
     * @param mixed                $saved    The saveObject() return value.
     * @param array<string, mixed> $fallback The original payload.
     *
     * @return array<string, mixed> The persisted object as an array.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    private function normaliseSaved(mixed $saved, array $fallback): array
    {
        if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
            return $saved->jsonSerialize();
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        return $fallback;

    }//end normaliseSaved()

    /**
     * Normalise a legacy consultation status value to the v0.2.0 enum.
     *
     * The schema v0.1.0 -> v0.2.0 bump renamed 'summarised' to
     * 'results-published'. Existing objects keep the old value until they are
     * next written, so every read path normalises defensively (the declarative
     * value migration, applied in PHP because OpenRegister has no per-value
     * migration block in this register).
     *
     * @param string $status The stored status value.
     *
     * @return string The normalised status value.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function normaliseConsultationStatus(string $status): string
    {
        if ($status === 'summarised') {
            return 'results-published';
        }

        return $status;

    }//end normaliseConsultationStatus()

    /**
     * Transition a PublicConsultation to a new lifecycle status.
     *
     * Validates the transition against the legal state machine (fail closed)
     * and persists the new status. The legacy 'summarised' value is normalised
     * to 'results-published' before the transition is evaluated.
     *
     * @param string $consultationId The consultation UUID.
     * @param string $newStatus      The target status.
     *
     * @return array<string, mixed> The updated consultation object.
     *
     * @throws \RuntimeException         When the consultation is not found.
     * @throws \InvalidArgumentException When the transition is illegal (fail closed).
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function transitionConsultation(string $consultationId, string $newStatus): array
    {
        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $consultationId, register: 'decidesk', schema: 'public-consultation');
        if ($entity === null) {
            throw new \RuntimeException("PublicConsultation {$consultationId} not found");
        }

        $consultation = $entity->jsonSerialize();
        $current      = $this->normaliseConsultationStatus(status: (string) ($consultation['status'] ?? 'draft'));

        $this->assertTransitionAllowed(
            current: $current,
            target: $newStatus,
            transitions: self::CONSULTATION_TRANSITIONS,
            label: 'consultation'
        );

        // Opening requires a future submissionDeadline so the window is real.
        if ($newStatus === 'open') {
            $deadline = ($consultation['submissionDeadline'] ?? null);
            if ($deadline !== null && $deadline !== '' && strtotime((string) $deadline) <= time()) {
                throw new \InvalidArgumentException('Cannot open a consultation whose submissionDeadline has already passed');
            }
        }

        $consultation['status'] = $newStatus;
        $saved = $objectService->saveObject(register: 'decidesk', schema: 'public-consultation', object: $consultation);

        return $this->normaliseSaved(saved: $saved, fallback: $consultation);

    }//end transitionConsultation()

    /**
     * Transition a ParticipatoryBudget round to a new lifecycle status.
     *
     * @param string $budgetId  The budget round UUID.
     * @param string $newStatus The target status.
     *
     * @return array<string, mixed> The updated budget round object.
     *
     * @throws \RuntimeException         When the round is not found.
     * @throws \InvalidArgumentException When the transition is illegal (fail closed).
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function transitionBudgetRound(string $budgetId, string $newStatus): array
    {
        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $budgetId, register: 'decidesk', schema: 'participatory-budget');
        if ($entity === null) {
            throw new \RuntimeException("ParticipatoryBudget {$budgetId} not found");
        }

        $round   = $entity->jsonSerialize();
        $current = (string) ($round['status'] ?? 'draft');

        $this->assertTransitionAllowed(
            current: $current,
            target: $newStatus,
            transitions: self::BUDGET_TRANSITIONS,
            label: 'budget round'
        );

        $round['status'] = $newStatus;
        $saved           = $objectService->saveObject(register: 'decidesk', schema: 'participatory-budget', object: $round);

        return $this->normaliseSaved(saved: $saved, fallback: $round);

    }//end transitionBudgetRound()

    /**
     * Assert that a state-machine transition is legal (fail closed).
     *
     * @param string                  $current     The current status.
     * @param string                  $target      The requested target status.
     * @param array<string, string[]> $transitions The legal transition map.
     * @param string                  $label       Human label for error messages.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the transition is not permitted.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    private function assertTransitionAllowed(string $current, string $target, array $transitions, string $label): void
    {
        if (isset($transitions[$current]) === false) {
            throw new \InvalidArgumentException("Unknown {$label} status '{$current}'");
        }

        if (in_array($target, $transitions[$current], true) === false) {
            throw new \InvalidArgumentException(
                sprintf("Illegal %s transition '%s' -> '%s'", $label, $current, $target)
            );
        }

    }//end assertTransitionAllowed()

    /**
     * Determine whether a consultation currently accepts reaction submissions.
     *
     * Accepts ONLY when status is 'open' AND submissionDeadline (if set) is in
     * the future. The deadline is enforced independently of the stored status,
     * so a consultation that was never auto-closed still rejects late
     * submissions.
     *
     * @param array<string, mixed> $consultation The consultation object.
     *
     * @return bool True when submissions are accepted.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function consultationAcceptsSubmissions(array $consultation): bool
    {
        $status = $this->normaliseConsultationStatus(status: (string) ($consultation['status'] ?? 'draft'));
        if ($status !== 'open') {
            return false;
        }

        return $this->deadlineInFuture(value: ($consultation['submissionDeadline'] ?? null));

    }//end consultationAcceptsSubmissions()

    /**
     * Determine whether a budget round currently accepts proposal submissions.
     *
     * @param array<string, mixed> $round The budget round object.
     *
     * @return bool True when proposal submission is open.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function budgetAcceptsProposals(array $round): bool
    {
        if ((string) ($round['status'] ?? 'draft') !== 'submission') {
            return false;
        }

        return $this->deadlineInFuture(value: ($round['submissionDeadline'] ?? null));

    }//end budgetAcceptsProposals()

    /**
     * Determine whether a budget round currently accepts advisory votes.
     *
     * @param array<string, mixed> $round The budget round object.
     *
     * @return bool True when the voting window is open.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function budgetAcceptsVotes(array $round): bool
    {
        if ((string) ($round['status'] ?? 'draft') !== 'voting') {
            return false;
        }

        return $this->deadlineInFuture(value: ($round['votingDeadline'] ?? null));

    }//end budgetAcceptsVotes()

    /**
     * Whether an (optional) deadline value is unset or strictly in the future.
     *
     * A null/empty deadline means "no deadline configured" and is treated as
     * still open. A past deadline closes the window regardless of stored status.
     *
     * @param mixed $value The deadline value (ISO-8601 string or null).
     *
     * @return bool True when the deadline has not passed.
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    private function deadlineInFuture(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            // Unparseable deadline: fail closed (treat as passed).
            return false;
        }

        return $timestamp > time();

    }//end deadlineInFuture()
}//end class
