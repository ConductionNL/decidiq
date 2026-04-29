<?php

/**
 * Decidesk MeetingTransitionGuard
 *
 * Implements OpenRegister's LifecycleGuardInterface for the Meeting
 * schema. Delegates to existing WorkflowService (domain-specific rules,
 * chair gates) and QuorumService (open-meeting quorum check).
 *
 * @category Lifecycle
 * @package  OCA\Decidesk\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

use OCA\Decidesk\Service\QuorumService;
use OCA\Decidesk\Service\WorkflowService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Guards Meeting transitions:
 * - All transitions: domain-allowed (legislative/corporate/operations/...).
 * - `open`: chair-only (when domain requires) + quorum met.
 * - `pause`/`resume`/`adjourn`: chair-only when domain requires it.
 *
 * Note: `schedule` does not declare `requires` in the schema, so this
 * guard is not invoked for it — `schedule` is a pure draft→scheduled
 * shape transition with no domain rules.
 */
final class MeetingTransitionGuard implements LifecycleGuardInterface
{

    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly QuorumService $quorumService
    ) {}//end __construct()

    public function check(array $object, string $action, string $userId): GuardResult
    {
        $domain      = (string) ($object['domain'] ?? 'operations');
        $chairUserId = ($object['chair'] ?? null);
        // The lifecycle field on $object is the *target* state because the
        // listener mutates first then validates. Recover from/to from the
        // declared transitions table here is not needed — domain rules and
        // chair rules already gate by from/to that the listener verified
        // shape-wise. We re-derive `to` from the action's known mapping.
        $toState = self::TRANSITION_TO[$action] ?? null;
        if ($toState === null) {
            return GuardResult::deny(sprintf('Unknown action "%s".', $action));
        }

        // The current value on $object is already the target. Reconstruct
        // a plausible "from" by checking which states the action allows in
        // the schema declaration would be brittle here; instead, rely on
        // the listener's earlier from-check and only enforce the
        // domain/chair/quorum rules.
        $fromState = self::deriveFrom(action: $action, toState: $toState);

        if ($this->workflowService->isTransitionAllowed(domain: $domain, fromState: $fromState, toState: $toState) === false) {
            return GuardResult::deny(
                sprintf('Transition "%s" is not permitted in the "%s" domain.', $action, $domain)
            );
        }

        if ($this->workflowService->requiresChairAuthorization(domain: $domain, from: $fromState, to: $toState) === true) {
            if ($userId === '' || $userId !== (string) $chairUserId) {
                return GuardResult::deny('Only the meeting chair may perform this transition.');
            }
        }

        if ($action === 'open' && $this->workflowService->isQuorumRequired(domain: $domain) === true) {
            $meetingId = (string) ($object['id'] ?? $object['uuid'] ?? '');
            if ($meetingId === '' || $this->quorumService->validateQuorum(meetingId: $meetingId) === false) {
                return GuardResult::deny('Quorum is not met. Cannot open meeting.');
            }
        }

        return GuardResult::allow();
    }//end check()

    /**
     * Action → resulting state. Mirrors the schema annotation. Kept here
     * to avoid a network/DB hop just to look it up; if the annotation
     * grows new actions, this map must be extended in lockstep.
     */
    private const TRANSITION_TO = [
        'schedule' => 'scheduled',
        'open'     => 'opened',
        'pause'    => 'paused',
        'resume'   => 'opened',
        'adjourn'  => 'adjourned',
        'close'    => 'closed',
    ];

    /**
     * Best-effort `from` derivation for guards. Domain rule checks only
     * care that the (from, to) tuple is allowed; the listener has already
     * confirmed the actual current state was in the schema's `from` list,
     * so for chair gates we use the canonical from per action.
     */
    private static function deriveFrom(string $action, string $toState): string
    {
        return match ($action) {
            'schedule' => 'draft',
            'open'     => 'scheduled',
            'pause'    => 'opened',
            'resume'   => 'paused',
            'adjourn'  => 'opened',
            'close'    => 'opened',
            default    => $toState,
        };
    }//end deriveFrom()

}//end class
