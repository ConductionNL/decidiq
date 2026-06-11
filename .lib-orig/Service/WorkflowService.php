<?php

/**
 * Decidesk Workflow Service
 *
 * Service for managing workflow transitions and governance-domain-specific configurations.
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
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;


/**
 * Service for managing meeting workflow transitions per governance domain.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.1
 */
class WorkflowService
{

    /**
     * Domain-specific workflow configurations.
     *
     * Each domain defines allowed transitions, quorum requirements, and chair-only gates.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DOMAIN_WORKFLOWS = [
        'legislative' => [
            'allowPause'           => true,
            'allowAdjourn'         => true,
            'quorumEnforced'       => true,
            'chairOnlyTransitions' => ['opened:adjourned'],
        ],
        'association' => [
            'allowPause'           => false,
            'allowAdjourn'         => true,
            'quorumEnforced'       => true,
            'chairOnlyTransitions' => [],
        ],
        'corporate'   => [
            'allowPause'           => false,
            'allowAdjourn'         => false,
            'quorumEnforced'       => true,
            'chairOnlyTransitions' => [],
        ],
        'operations'  => [
            'allowPause'           => false,
            'allowAdjourn'         => false,
            'quorumEnforced'       => false,
            'chairOnlyTransitions' => [],
        ],
        'citizen'     => [
            'allowPause'           => false,
            'allowAdjourn'         => true,
            'quorumEnforced'       => false,
            'chairOnlyTransitions' => [],
        ],
    ];

    /**
     * Default-deny fallback workflow used for unrecognized domains (#314).
     *
     * Unknown domains fall back to the most restrictive configuration: quorum
     * enforced, no pause, no adjourn, no chair-only overrides. This prevents
     * a mis-typed or injected domain string from silently granting the permissive
     * 'operations' workflow.
     *
     * @var array<string, mixed>
     */
    private const RESTRICTED_WORKFLOW = [
        'allowPause'           => false,
        'allowAdjourn'         => false,
        'quorumEnforced'       => true,
        'chairOnlyTransitions' => [],
    ];

    /**
     * Constructor for WorkflowService.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.1
     */
    public function __construct()
    {
    }//end __construct()

    /**
     * Get the workflow configuration for a given domain.
     *
     * #314: Unknown domains fall back to RESTRICTED_WORKFLOW (default-deny) instead of
     * the permissive 'operations' workflow, so a mis-typed or injected domain string
     * cannot silently grant elevated permissions.
     *
     * @param string $domain The governance domain (legislative|association|corporate|operations|citizen)
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.1
     *
     * @return array<string, mixed> The domain's workflow configuration
     */
    public function getDomainWorkflow(string $domain): array
    {
        return self::DOMAIN_WORKFLOWS[$domain] ?? self::RESTRICTED_WORKFLOW;
    }//end getDomainWorkflow()

    /**
     * Validate whether a state transition is allowed for a given governance body domain.
     *
     * #313: Domain allow-flags are evaluated FIRST so they cannot be short-circuited
     * by a match in chairOnlyTransitions. A chair-only transition that is also
     * domain-forbidden must still return false. Callers must separately verify
     * chair authorization via requiresChairAuthorization() when this returns true.
     *
     * @param string $domain    The governance domain
     * @param string $fromState The current lifecycle state
     * @param string $toState   The target lifecycle state
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     *
     * @return bool True if the transition is permitted by domain rules (does NOT imply chair auth satisfied)
     */
    public function isTransitionAllowed(string $domain, string $fromState, string $toState): bool
    {
        $workflow = $this->getDomainWorkflow(domain: $domain);

        // #313: Evaluate domain-level allow-flags FIRST — these override everything.
        if ($fromState === 'opened' && $toState === 'paused' && ($workflow['allowPause'] ?? true) === false) {
            return false;
        }

        if ($fromState === 'opened' && $toState === 'adjourned' && ($workflow['allowAdjourn'] ?? true) === false) {
            return false;
        }

        // Chair-only transitions are allowed by domain rules; the caller must separately
        // enforce that the actor holds the chair role via requiresChairAuthorization().
        return true;
    }//end isTransitionAllowed()

    /**
     * Check if quorum is required before transitioning to opened state.
     *
     * @param string $domain The governance domain
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.4
     *
     * @return bool True if quorum must be enforced
     */
    public function isQuorumRequired(string $domain): bool
    {
        $workflow = $this->getDomainWorkflow(domain: $domain);
        return $workflow['quorumEnforced'] ?? false;
    }//end isQuorumRequired()

    /**
     * Check if a transition requires chair-only authorization.
     *
     * @param string $domain The governance domain
     * @param string $from   The current state
     * @param string $to     The target state
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.1
     *
     * @return bool True if the transition requires chair authorization
     */
    public function requiresChairAuthorization(string $domain, string $from, string $to): bool
    {
        $workflow   = $this->getDomainWorkflow(domain: $domain);
        $transition = "$from:$to";
        return in_array(needle: $transition, haystack: $workflow['chairOnlyTransitions'] ?? [], strict: true);
    }//end requiresChairAuthorization()
}//end class
