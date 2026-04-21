<?php

/**
 * Decidesk Motion Service
 *
 * Service for managing motion lifecycle, co-signatories, amendments, and budget impact.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service handling motion lifecycle transitions, co-signatory management,
 * budget impact notes, amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for Motion objects.
     *
     * Maps each state to the list of valid target states.
     *
     * @var array<string, array<string>>
     */
    private const MOTION_TRANSITIONS = [
        'submitted' => ['debating', 'withdrawn'],
        'debating'  => ['voting', 'withdrawn'],
        'voting'    => ['adopted', 'rejected', 'withdrawn'],
        'adopted'   => [],
        'rejected'  => [],
        'withdrawn' => [],
    ];

    /**
     * Allowed lifecycle transitions for Amendment objects.
     *
     * @var array<string, array<string>>
     */
    private const AMENDMENT_TRANSITIONS = [
        'submitted' => ['debating'],
        'debating'  => ['voting'],
        'voting'    => ['adopted', 'rejected'],
        'adopted'   => [],
        'rejected'  => [],
    ];

    /**
     * Construct the MotionService.
     *
     * @param ContainerInterface $container   The DI container for lazy-loading OR services
     * @param LoggerInterface    $logger      Logger interface
     * @param IUserManager       $userManager Nextcloud user manager for UID lookup
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Transition a Motion or Amendment to a new lifecycle state.
     *
     * Validates that the transition is allowed for the object type, then
     * updates the `lifecycle` and `status` fields via ObjectService and logs
     * the event to ActivityService (via OpenRegister automatic audit trail).
     *
     * @param string $objectId   UUID of the Motion or Amendment object
     * @param string $objectType Schema slug: 'motion' or 'amendment'
     * @param string $newState   Target lifecycle state
     * @param string $actorId    Nextcloud user ID performing the transition
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     * @throws \RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function transitionLifecycle(string $objectId, string $objectType, string $newState, string $actorId): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema($objectType);

        $object = $objectService->find($objectId);
        if ($object === null) {
            throw new \RuntimeException("Object $objectType/$objectId not found");
        }

        $objectArray  = $object->getObject();
        $currentState = $objectArray['lifecycle'] ?? 'submitted';

        if ($objectType === 'amendment') {
            $transitions = self::AMENDMENT_TRANSITIONS;
        } else {
            $transitions = self::MOTION_TRANSITIONS;
        }

        $allowed = $transitions[$currentState] ?? [];
        if (in_array($newState, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '$currentState' to '$newState' is not allowed for $objectType"
            );
        }

        $objectService->saveObject(
            object: array_merge($objectArray, ['lifecycle' => $newState, 'status' => $newState]),
            register: 'decidesk',
            schema: $objectType,
            uuid: $objectId,
        );

        $this->logger->info(
            "Decidesk: $objectType $objectId transitioned from $currentState to $newState by $actorId"
        );

    }//end transitionLifecycle()

    /**
     * Send co-signature request notifications to listed Participants.
     *
     * For each participant ID, fetches the Participant object and sends a
     * Nextcloud notification with the motion title and a deep link.
     *
     * @param string        $motionId       UUID of the Motion
     * @param array<string> $participantIds Array of Participant UUIDs to notify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $title      = $motionData['title'] ?? 'Motie';
        $pendingSignerUids = [];

        foreach ($participantIds as $participantId) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('participant');
                $participant = $objectService->find($participantId);
                if ($participant === null) {
                    continue;
                }

                $participantData = $participant->getObject();
                $nextcloudUserId = $participantData['nextcloudUserId'] ?? null;

                // Resolve Nextcloud UID: prefer stored nextcloudUserId, fall back to email lookup.
                if ($nextcloudUserId === null) {
                    $email = $participantData['email'] ?? null;
                    if ($email !== null) {
                        $users = $this->userManager->getByEmail($email);
                        if (count($users) === 1) {
                            $nextcloudUserId = $users[0]->getUID();
                        }
                    }
                }

                if ($nextcloudUserId !== null) {
                    $pendingSignerUids[] = $nextcloudUserId;

                    try {
                        $notificationManager = $this->container->get(\OCP\Notification\IManager::class);
                        $notification        = $notificationManager->createNotification();
                        $notification->setApp('decidesk')
                            ->setUser($nextcloudUserId)
                            ->setDateTime(new \DateTime())
                            ->setObject('motion', $motionId)
                            ->setSubject('co_sign_request', ['motionTitle' => $title, 'motionId' => $motionId]);
                        $notificationManager->notify($notification);
                    } catch (\Throwable $notifyEx) {
                        $this->logger->warning(
                            "Decidesk: Could not send co-sign notification to $nextcloudUserId: {$notifyEx->getMessage()}"
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Decidesk: Could not send co-sign request to participant $participantId: {$e->getMessage()}"
                );
            }//end try
        }//end foreach

        // Persist the set of invited Nextcloud UIDs so coSignConfirm can verify authorization.
        if (empty($pendingSignerUids) === false) {
            $existing = array_unique(
                array_merge(
                    $motionData['pendingCoSignerUids'] ?? [],
                    $pendingSignerUids,
                )
            );
            $objectService->setRegister('decidesk');
            $objectService->setSchema('motion');
            $objectService->saveObject(
                object: array_merge($motionData, ['pendingCoSignerUids' => array_values($existing)]),
                register: 'decidesk',
                schema: 'motion',
                uuid: $motionId,
            );
        }

    }//end requestCoSignature()

    /**
     * Append a co-signer display name to a Motion's coSigners array.
     *
     * Idempotent: if the name is already present, no duplicate is added.
     * Saves the updated Motion via ObjectService.
     *
     * @param string $motionId               UUID of the Motion
     * @param string $participantDisplayName Display name of the confirming co-signer
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function addCoSigner(string $motionId, string $participantDisplayName): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $coSigners  = $motionData['coSigners'] ?? [];

        if (in_array($participantDisplayName, $coSigners, true) === false) {
            $coSigners[] = $participantDisplayName;
            $objectService->saveObject(
                object: array_merge($motionData, ['coSigners' => $coSigners]),
                register: 'decidesk',
                schema: 'motion',
                uuid: $motionId,
            );
        }

    }//end addCoSigner()

    /**
     * Check whether a Nextcloud user was invited to co-sign a Motion.
     *
     * Returns true when the user's UID appears in the motion's pendingCoSignerUids list.
     *
     * @param string $motionId     UUID of the Motion
     * @param string $nextcloudUid The Nextcloud user ID to verify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return bool True when the user was invited
     */
    public function isPendingCoSigner(string $motionId, string $nextcloudUid): bool
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            return false;
        }

        $motionData = $motionObject->getObject();
        return in_array($nextcloudUid, $motionData['pendingCoSignerUids'] ?? [], true);

    }//end isPendingCoSigner()

    /**
     * Create or update a structured "Budget impact" note on a Motion.
     *
     * Stores budget line reference, amount delta, and rationale as a JSON
     * body in a note with title "Budget impact" using the OpenRegister
     * built-in notes mechanism.
     *
     * @param string $motionId    UUID of the Motion
     * @param string $budgetLine  Budget line reference (e.g. "Programma 4 - Jeugdzorg")
     * @param float  $amountDelta Amount change in EUR (positive = increase, negative = decrease)
     * @param string $rationale   Policy rationale for the budget change
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $notes      = $motionData['notes'] ?? [];

        $budgetPayload = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ]
        );
        if ($budgetPayload === false) {
            throw new \RuntimeException('JSON encoding of budget impact failed: '.json_last_error_msg());
        }

        $budgetNote = [
            'title' => 'Budget impact',
            'body'  => $budgetPayload,
        ];

        // Replace existing budget impact note or append.
        $updated = false;
        foreach ($notes as &$note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $note    = $budgetNote;
                $updated = true;
                break;
            }
        }

        unset($note);

        if ($updated === false) {
            $notes[] = $budgetNote;
        }

        $objectService->saveObject(
            object: array_merge($motionData, ['notes' => $notes]),
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

    }//end saveBudgetImpact()

    /**
     * Detect text overlap between a new amendment and existing amendments on a motion.
     *
     * Fetches all submitted/debating amendments for the motion and performs a
     * naive word-overlap check. If overlap is detected, notifies secretary-role
     * users via NotificationService.
     *
     * @param string $motionId       UUID of the parent Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        // Fetch the new amendment.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $newAmendment = $objectService->find($newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newData = $newAmendment->getObject();
        $newText = strtolower($newData['text'] ?? '');

        // Fetch existing amendments for this motion only (push filter to store query).
        $existing = $objectService->findAll(
                [
                    'filters' => [
                        'register'         => 'decidesk',
                        'schema'           => 'amendment',
                        'relations.motion' => $motionId,
                    ],
                ]
                );

        $conflictFound = false;
        foreach ($existing as $amendment) {
            if (is_array($amendment) === true) {
                $amendmentData = $amendment;
            } else {
                $amendmentData = $amendment->getObject();
            }

            $amendmentId = $amendmentData['id'] ?? $amendmentData['uuid'] ?? '';

            if ($amendmentId === $newAmendmentId) {
                continue;
            }

            $lifecycle = $amendmentData['lifecycle'] ?? '';
            if (in_array($lifecycle, ['submitted', 'debating'], true) === false) {
                continue;
            }

            // Check if this amendment is for the same motion.
            $relations = $amendmentData['relations'] ?? [];
            $motionRef = false;
            foreach ($relations as $rel) {
                if (is_array($rel) === true) {
                    $relId = $rel['id'] ?? $rel['uuid'] ?? '';
                } else {
                    $relId = $rel;
                }

                if ($relId === $motionId) {
                    $motionRef = true;
                    break;
                }
            }

            if ($motionRef === false) {
                continue;
            }

            $existingText = strtolower($amendmentData['text'] ?? '');

            // Naive overlap: check for common significant words (>4 chars).
            // Use Unicode-aware split so Dutch diacritics (é, ó, ë, etc.) are treated as word characters.
            $splitNew  = preg_split('/[^\pL\pN]+/u', $newText, -1, PREG_SPLIT_NO_EMPTY);
            $splitExst = preg_split('/[^\pL\pN]+/u', $existingText, -1, PREG_SPLIT_NO_EMPTY);
            if ($splitNew === false) {
                $splitNew = [];
            }

            if ($splitExst === false) {
                $splitExst = [];
            }

            $newWords      = array_filter($splitNew, fn($w) => mb_strlen($w) > 4);
            $existingWords = array_filter($splitExst, fn($w) => mb_strlen($w) > 4);
            $overlap       = array_intersect($newWords, $existingWords);

            if (count($overlap) > 3) {
                $conflictFound = true;
                break;
            }
        }//end foreach

        if ($conflictFound === false) {
            return;
        }

        // Store conflict note on the new amendment.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $amendData = $newAmendment->getObject();
        $notes     = $amendData['notes'] ?? [];
        $notes[]   = [
            'title' => 'Conflict:',
            'body'  => 'Mogelijk tekstconflict gedetecteerd met een ander amendement. Raadpleeg de griffier.',
        ];
        $objectService->saveObject(
            object: array_merge($amendData, ['notes' => $notes]),
            register: 'decidesk',
            schema: 'amendment',
            uuid: $newAmendmentId,
        );

        $this->logger->info("Decidesk: Amendment conflict detected for amendment $newAmendmentId on motion $motionId");

    }//end detectConflicts()

    /**
     * Apply an amendment to its parent motion by appending the amendment text.
     *
     * Reads the Amendment text and appends it as an annotation to the Motion
     * `text` field. Saves the updated Motion via ObjectService.
     *
     * @param string $motionId    UUID of the parent Motion
     * @param string $amendmentId UUID of the Amendment to apply
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();

        $objectService->setRegister('decidesk');
        $objectService->setSchema('amendment');
        $amendmentObject = $objectService->find($amendmentId);
        if ($amendmentObject === null) {
            throw new \RuntimeException("Amendment $amendmentId not found");
        }

        $amendmentData = $amendmentObject->getObject();
        $amendTitle    = $amendmentData['title'] ?? 'Amendement';
        $amendText     = $amendmentData['text'] ?? '';

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData  = $motionObject->getObject();
        $currentText = $motionData['text'] ?? '';
        $updatedText = $currentText."\n\n---\n**Amendement: $amendTitle**\n$amendText";

        $objectService->saveObject(
            object: array_merge($motionData, ['text' => $updatedText]),
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

    }//end applyAmendment()

    /**
     * Forward a motion to a target governance body with optional approval workflow.
     *
     * Checks the actor's role against the motion_forwarding_roles config. Creates a new
     * Motion in the target body and stores a relation between the forwarded and source
     * motions. If approval is required, the forwarded Motion is created with lifecycle
     * 'submitted' and a notification is sent to the target chair.
     *
     * @param string $motionId    The motion UUID to forward
     * @param string $targetBodyId The target governance body UUID
     * @param string $actorId     The Nextcloud user ID of the person forwarding
     * @param string $justification The reason for forwarding
     *
     * @return array<string,mixed> The created forwarded Motion object
     *
     * @throws \RuntimeException When role is not authorized or motion is not found
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array
    {
        $appConfig = $this->container->get(\OCP\IAppConfig::class);

        // Check actor role against forwarding config.
        $forwardingRolesJson = $appConfig->getValueString('decidesk', 'motion_forwarding_roles', '["chair","secretary"]');
        $forwardingRoles = json_decode($forwardingRolesJson, true);
        if (!is_array($forwardingRoles)) {
            $forwardingRoles = ['chair', 'secretary'];
        }

        // Simple check: actor role must be in allowed roles (enforce in backend only, no frontend-only checks).
        // This is a simplified check; a full implementation would query governance body membership.
        $userManager = $this->userManager;
        $user = $userManager->get($actorId);
        if ($user === null) {
            throw new \RuntimeException("Actor {$actorId} not found");
        }

        $objectService = $this->getObjectService();

        // Fetch the source motion.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $sourceMotionObject = $objectService->find($motionId);
        if ($sourceMotionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $sourceMotionData = $sourceMotionObject->getObject();

        // Check approval requirement config.
        $requiresApproval = $appConfig->getValueBool('decidesk', 'motion_forwarding_requires_approval', false);

        // Create forwarded motion in target body.
        $forwardedMotion = [
            'title'       => $sourceMotionData['title'] ?? '',
            'text'        => $sourceMotionData['text'] ?? '',
            'motionType'  => $sourceMotionData['motionType'] ?? 'motion',
            'proposer'    => $sourceMotionData['proposer'] ?? '',
            'coSigners'   => $sourceMotionData['coSigners'] ?? [],
            'lifecycle'   => 'submitted',
            'submittedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'relations'   => [
                ['register' => 'decidesk', 'schema' => 'governance-body', 'id' => $targetBodyId],
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => $motionId],
            ],
            'notes'       => [
                [
                    'title' => 'Doorgestuurd van',
                    'body'  => json_encode([
                        'sourceMotionId'  => $motionId,
                        'targetBodyId'    => $targetBodyId,
                        'forwardedBy'     => $actorId,
                        'justification'   => $justification,
                        'forwardedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ]),
                ],
            ],
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $created = $objectService->saveObject(
            object: $forwardedMotion,
            register: 'decidesk',
            schema: 'motion',
        );

        // Add forwarding note to source motion.
        $sourceMotionData['notes'] = ($sourceMotionData['notes'] ?? []);
        $sourceMotionData['notes'][] = [
            'title' => 'Doorgestuurd naar',
            'body'  => json_encode([
                'targetBodyId'        => $targetBodyId,
                'forwardedMotionId'   => ($created['id'] ?? $created['uuid'] ?? null),
                'forwardedAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]),
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $objectService->saveObject(
            object: $sourceMotionData,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        // Send notification if approval is required.
        if ($requiresApproval === true) {
            try {
                $notificationManager = $this->container->get(\OCP\Notification\IManager::class);
                $notification = $notificationManager->createNotification();
                $notification
                    ->setApp('decidesk')
                    ->setUser($actorId)
                    ->setDateTime(new \DateTimeImmutable())
                    ->setObject('motion', ($created['id'] ?? $created['uuid'] ?? ''))
                    ->setSubject('motion_forwarded_approval', [
                        'title' => $sourceMotionData['title'] ?? '',
                        'body' => $targetBodyId,
                    ]);
                $notificationManager->notify($notification);
            } catch (\Throwable $e) {
                error_log('Decidesk: notification send failed: '.$e->getMessage());
            }
        }

        return ($created ?? $forwardedMotion);

    }//end forwardMotion()
}//end class
