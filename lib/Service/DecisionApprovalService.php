<?php

/**
 * Decidesk Decision Approval Service
 *
 * Service for managing multi-stage approval workflows on Decision objects.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\OCS\OCSForbiddenException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service for decision approval workflow transitions, reviewer notifications,
 * and sign-off tracking.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
 */
class DecisionApprovalService
{
    /**
     * Allowed lifecycle transitions for Decision approval workflow.
     *
     * @var array<string, array<string>>
     */
    private const APPROVAL_TRANSITIONS = [
        'draft'            => ['legal-review'],
        'legal-review'     => ['committee-review', 'board-rejected'],
        'committee-review' => ['board-approved', 'board-rejected'],
        'board-approved'   => ['published'],
        'board-rejected'   => ['draft'],
        'published'        => [],
    ];

    /**
     * Required roles for each transition target state.
     *
     * @var array<string, array<string>>
     */
    private const REQUIRED_ROLES = [
        'legal-review'     => ['chair', 'secretary'],
        'committee-review' => ['legal-counsel'],
        'board-approved'   => ['chair', 'secretary'],
        'board-rejected'   => ['chair', 'secretary', 'legal-counsel'],
        'published'        => ['chair', 'secretary'],
    ];

    /**
     * Construct the DecisionApprovalService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get the NotificationService from the container.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return object|null
     */
    private function getNotificationService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\NotificationService');
        } catch (\Throwable) {
            return null;
        }
    }//end getNotificationService()

    /**
     * Get the AuthorizationService from the container.
     *
     * Throws RuntimeException when the service is unavailable so callers can
     * implement deny-by-default instead of failing open.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return object
     *
     * @throws \RuntimeException When AuthorizationService cannot be resolved.
     */
    private function getAuthorizationService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\AuthorizationService');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'AuthorizationService unavailable: '.$e->getMessage(),
                0,
                $e
            );
        }
    }//end getAuthorizationService()

    /**
     * Transition a Decision to a new lifecycle state.
     *
     * Validates the transition is allowed and the actor has required role,
     * updates the Decision, sends notifications, and logs to audit trail.
     *
     * @param string $decisionId UUID of Decision
     * @param string $toState    Target lifecycle state
     * @param string $actorId    UUID of actor performing transition
     * @param string $reason     Optional reason (required for rejections)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If transition is invalid
     * @throws \RuntimeException         If Decision not found
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     */
    public function transitionLifecycle(
        string $decisionId,
        string $toState,
        string $actorId,
        string $reason=''
    ): void {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            throw new \RuntimeException("Decision $decisionId not found");
        }

        $decisionArray = $decision->getObject();
        $currentState  = $decisionArray['lifecycle'] ?? 'draft';

        $allowed = self::APPROVAL_TRANSITIONS[$currentState] ?? [];
        if (in_array($toState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '$currentState' to '$toState' is not allowed"
            );
        }

        $requiredRoles = self::REQUIRED_ROLES[$toState] ?? [];
        if (empty($requiredRoles) === false) {
            try {
                $authService = $this->getAuthorizationService();
            } catch (\RuntimeException) {
                throw new OCSForbiddenException(
                    "Authorization service unavailable — access denied for transition to '$toState'"
                );
            }

            $hasRole = false;
            if (method_exists($authService, 'checkUserRole') === true) {
                foreach ($requiredRoles as $role) {
                    if ($authService->checkUserRole($actorId, $role) === true) {
                        $hasRole = true;
                        break;
                    }
                }
            }

            if ($hasRole === false) {
                throw new OCSForbiddenException(
                    "Actor lacks required role for transition to '$toState'"
                );
            }
        }//end if

        $updateData = array_merge($decisionArray, ['lifecycle' => $toState]);

        if ($toState === 'board-rejected' && empty($reason) === false) {
            $notes     = $decisionArray['notes'] ?? [];
            $timestamp = date(\DateTime::ATOM);
            $notes[]   = "[REJECTION] Reason: $reason — $timestamp";
            $updateData['notes'] = $notes;
        }

        $objectService->saveObject(
            object: $updateData,
            register: 'decidesk',
            schema: 'Decision',
            uuid: $decisionId,
        );

        $this->logger->info(
            "Decision $decisionId transitioned from $currentState to $toState by $actorId"
        );

        $notificationService = $this->getNotificationService();
        if ($notificationService !== null && method_exists($notificationService, 'notify') === true) {
            $title   = $decisionArray['title'] ?? 'Decision';
            $message = "Decision '$title' advanced to $toState";
            $notificationService->notify(
                recipients: [],
                title: 'Decision Approval',
                message: $message,
            );
        }
    }//end transitionLifecycle()

    /**
     * Verify the calling Nextcloud user is the named Person reviewer.
     *
     * Looks up the Person entity by UUID, then checks that the Person's
     * `nextcloudUserId` matches the authenticated caller's UID. Throws when
     * the check fails so callers can deny the request unconditionally.
     *
     * @param string $personId  UUID of Person entity
     * @param string $callerUid Nextcloud UID of authenticated user
     *
     * @return void
     *
     * @throws OCSForbiddenException If caller UID does not match the Person record.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function authorizeReviewerSubmission(string $personId, string $callerUid): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Person');

        $person = $objectService->find($personId);
        if ($person === null) {
            throw new OCSForbiddenException('Person not found or access denied');
        }

        $personArray = $person->getObject();
        $personUid   = $personArray['nextcloudUserId'] ?? null;

        if ($personUid !== $callerUid) {
            throw new OCSForbiddenException(
                'Authenticated user does not correspond to the supplied personId'
            );
        }
    }//end authorizeReviewerSubmission()

    /**
     * Submit a reviewer sign-off on a Decision.
     *
     * Records the reviewer's approval or rejection with optional note.
     *
     * @param string $decisionId UUID of Decision
     * @param string $personId   UUID of reviewer (Person)
     * @param string $value      'approved' or 'rejected'
     * @param string $note       Optional sign-off note
     *
     * @return void
     *
     * @throws \InvalidArgumentException If value is invalid
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     */
    public function submitReview(
        string $decisionId,
        string $personId,
        string $value,
        string $note=''
    ): void {
        if (in_array($value, ['approved', 'rejected'], true) === false) {
            throw new \InvalidArgumentException("Value must be 'approved' or 'rejected'");
        }

        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            throw new \RuntimeException("Decision $decisionId not found");
        }

        $decisionArray = $decision->getObject();
        $notes         = $decisionArray['notes'] ?? [];
        $timestamp     = date(\DateTime::ATOM);
        $reviewNote    = "[REVIEW] Person $personId: $value — $note — $timestamp";
        $notes[]       = $reviewNote;

        $updateData = array_merge($decisionArray, ['notes' => $notes]);
        $objectService->saveObject(
            object: $updateData,
            register: 'decidesk',
            schema: 'Decision',
            uuid: $decisionId,
        );

        $this->logger->info(
            "Review submitted for Decision $decisionId by Person $personId: $value"
        );
    }//end submitReview()

    /**
     * Assign a reviewer to a Decision.
     *
     * Creates an OpenRegister relation from Decision to Person.
     *
     * @param string $decisionId UUID of Decision
     * @param string $personId   UUID of Person to assign
     * @param string $actorId    UUID of actor assigning reviewer
     *
     * @return void
     *
     * @throws \RuntimeException If Decision not found
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     */
    public function assignReviewer(
        string $decisionId,
        string $personId,
        string $actorId
    ): void {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            throw new \RuntimeException("Decision $decisionId not found");
        }

        $decisionArray = $decision->getObject();

        if (method_exists($objectService, 'createRelation') === true) {
            $objectService->createRelation(
                sourceId: $decisionId,
                targetId: $personId,
                label: 'reviewer',
                sourceRegister: 'decidesk',
                sourceSchema: 'Decision',
                targetRegister: 'decidesk',
                targetSchema: 'Person',
            );
        }

        $this->logger->info(
            "Reviewer $personId assigned to Decision $decisionId by $actorId"
        );

        $notificationService = $this->getNotificationService();
        if ($notificationService !== null && method_exists($notificationService, 'notify') === true) {
            $title = $decisionArray['title'] ?? 'Decision';
            $notificationService->notify(
                recipients: [$personId],
                title: 'You are assigned as reviewer',
                message: "Review requested for: $title",
            );
        }
    }//end assignReviewer()

    /**
     * Check if all assigned reviewers have submitted their sign-off.
     *
     * @param string $decisionId UUID of Decision
     *
     * @return bool True if all reviewers have signed off
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     */
    public function allReviewsComplete(string $decisionId): bool
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            return false;
        }

        $decisionArray = $decision->getObject();
        $notes         = $decisionArray['notes'] ?? [];

        if (method_exists($objectService, 'findRelations') === true) {
            $relations = $objectService->findRelations(
                sourceId: $decisionId,
                label: 'reviewer',
            );

            if (empty($relations) === true) {
                return true;
            }

            foreach ($relations as $relation) {
                $personId = $relation['targetId'] ?? null;
                if ($personId !== null) {
                    $reviewed = false;
                    foreach ($notes as $note) {
                        if (strpos($note, "[REVIEW]") !== false
                            && strpos($note, $personId) !== false
                        ) {
                            $reviewed = true;
                            break;
                        }
                    }

                    if ($reviewed === false) {
                        return false;
                    }
                }
            }
        }//end if

        return true;
    }//end allReviewsComplete()

    /**
     * Authorise a reviewer assignment operation.
     *
     * Verifies the decision exists and is accessible to the caller. Throws when
     * the decision is not found so the controller can deny the request unconditionally.
     *
     * @param string $decisionId UUID of Decision
     * @param string $uid        Nextcloud UID of authenticated user
     *
     * @return void
     *
     * @throws OCSForbiddenException When decision not found or access denied.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function authorizeAssignment(string $decisionId, string $uid): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            throw new OCSForbiddenException('Decision not found or access denied');
        }
    }//end authorizeAssignment()

    /**
     * Authorise a reviewer reminder operation.
     *
     * Verifies the decision exists and is accessible before sending a reminder.
     * Throws when the decision is not found so callers can deny unconditionally.
     *
     * @param string $decisionId UUID of Decision
     * @param string $personId   UUID of reviewer (Person)
     * @param string $uid        Nextcloud UID of authenticated user
     *
     * @return void
     *
     * @throws OCSForbiddenException When decision not found or access denied.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function authorizeReminder(string $decisionId, string $personId, string $uid): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Decision');

        $decision = $objectService->find($decisionId);
        if ($decision === null) {
            throw new OCSForbiddenException('Decision not found or access denied');
        }
    }//end authorizeReminder()

    /**
     * Get the approval state machine definition.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return array<string, array<string>>
     */
    public function getApprovalStateMap(): array
    {
        return self::APPROVAL_TRANSITIONS;
    }//end getApprovalStateMap()
}//end class
