<?php
/**
 * Decidesk Action-Item Delegation Service
 *
 * Maps action-item delegation/substitute/reclaim semantics onto the
 * canonical OpenRegister `action-item` object instead of a separate
 * in-app `Task` / `Delegation` object store (ADR-022). Reassignment is an
 * assignee change on the action-item; reclaim reverts the assignee to the
 * original delegator. Both writes go through OpenRegister's saveObject(),
 * which produces the immutable audit-trail entry that preserves the
 * governance-relevant reclaim fact (design.md D2).
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
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service that represents delegation and reclaim of an action item as
 * assignee mutations on the canonical action-item OpenRegister object.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 */
class ActionItemDelegationService
{

    /**
     * Construct the service.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR ObjectService)
     * @param LoggerInterface    $logger    PSR-3 logger
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Load a canonical action-item object by UUID.
     *
     * @param string $actionItemId UUID of the action-item object
     *
     * @return array<string, mixed>|null The serialised object or null
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function findActionItem(string $actionItemId): ?array
    {
        $entity = $this->objectService()->find(
            id: $actionItemId,
            register: 'decidesk',
            schema: 'action-item',
        );
        if ($entity === null) {
            return null;
        }

        return $entity->getObject();

    }//end findActionItem()

    /**
     * Reassign an action item to a substitute.
     *
     * Records the original assignee as the `delegator` (so the item can be
     * reclaimed later) and sets the new assignee, optionally with a
     * substitute window. The deck card projection reflects the assignee
     * change. The OpenRegister audit trail records the write automatically.
     *
     * Only the current assignee or the existing delegator may reassign the
     * item (OWASP A01:2021 / ADR-005). Pass `$callerUid = null` only from
     * background-job or admin-only paths that enforce their own access check.
     *
     * @param string      $actionItemId    UUID of the action-item object
     * @param string      $substitute      The substitute participant taking the item
     * @param string|null $callerUid       Nextcloud UID of the requester (null = skip check)
     * @param string|null $substituteUntil Optional ISO-8601 end of the substitute window
     *
     * @return array<string, mixed> The updated action-item object
     *
     * @throws RuntimeException         When the action item cannot be found
     * @throws InvalidArgumentException When the caller lacks permission or input is invalid
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function reassign(
        string $actionItemId,
        string $substitute,
        ?string $callerUid=null,
        ?string $substituteUntil=null,
    ): array {
        $substitute = trim($substitute);
        if ($substitute === '') {
            throw new InvalidArgumentException('A substitute assignee is required for reassignment');
        }

        $item = $this->findActionItem(actionItemId: $actionItemId);
        if ($item === null) {
            throw new RuntimeException("Action item $actionItemId not found");
        }

        $currentAssignee = (string) ($item['assignee'] ?? '');
        $delegator       = (string) ($item['delegator'] ?? '');

        // OWASP A01 — only the current assignee or the existing delegator may reassign.
        if ($callerUid !== null) {
            if ($callerUid !== $currentAssignee && $callerUid !== $delegator) {
                throw new InvalidArgumentException(
                    'Only the current assignee or the delegator may reassign this action item'
                );
            }
        }

        // Preserve the first owner as the delegator so reclaim can revert to them.
        if ($delegator === '') {
            $item['delegator'] = $currentAssignee;
        }

        $item['assignee'] = $substitute;

        if ($substituteUntil !== null) {
            $isoPattern = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?Z?)?$/';
            if (preg_match($isoPattern, $substituteUntil) !== 1) {
                throw new InvalidArgumentException('substituteUntil must be an ISO-8601 date/time string');
            }

            $item['substituteUntil'] = $substituteUntil;
        }

        $this->objectService()->saveObject(
            object: $item,
            register: 'decidesk',
            schema: 'action-item',
            uuid: $actionItemId,
        );

        $this->logger->info(
            'Decidesk: action item reassigned to substitute',
            [
                'actionItemId' => $actionItemId,
                'delegator'    => ($item['delegator'] ?? ''),
                'substitute'   => $substitute,
                'by'           => $callerUid,
            ]
        );

        return $item;

    }//end reassign()

    /**
     * Reclaim an action item from a substitute back to the original delegator.
     *
     * Reverts the assignee to the recorded `delegator`, clears the substitute
     * window, and stamps the reclaim time. The OpenRegister write produces the
     * immutable audit-trail entry that preserves the formal "delegator
     * reclaimed item X" governance fact (design.md D2) — no bespoke
     * Delegation object is involved.
     *
     * Only the original delegator may reclaim (OWASP A01:2021). Pass
     * `$callerUid = null` only from admin-only paths that enforce their own
     * access check.
     *
     * @param string      $actionItemId UUID of the action-item object
     * @param string|null $callerUid    Nextcloud UID of the requester (null = skip check)
     *
     * @return array<string, mixed> The updated action-item object
     *
     * @throws RuntimeException         When the action item cannot be found
     * @throws InvalidArgumentException When the caller is not the delegator or nothing to reclaim
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.3
     */
    public function reclaim(string $actionItemId, ?string $callerUid=null): array
    {
        $item = $this->findActionItem(actionItemId: $actionItemId);
        if ($item === null) {
            throw new RuntimeException("Action item $actionItemId not found");
        }

        $delegator = (string) ($item['delegator'] ?? '');
        if ($delegator === '') {
            throw new InvalidArgumentException('This action item has not been delegated and cannot be reclaimed');
        }

        // OWASP A01 — only the original delegator may reclaim.
        if ($callerUid !== null && $callerUid !== $delegator) {
            throw new InvalidArgumentException('Only the original delegator may reclaim this action item');
        }

        $item['assignee']        = $delegator;
        $item['reclaimedAt']     = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $item['substituteUntil'] = null;

        $this->objectService()->saveObject(
            object: $item,
            register: 'decidesk',
            schema: 'action-item',
            uuid: $actionItemId,
        );

        $this->logger->info(
            'Decidesk: action item reclaimed by delegator',
            [
                'actionItemId' => $actionItemId,
                'delegator'    => $delegator,
                'by'           => $callerUid,
            ]
        );

        return $item;

    }//end reclaim()
}//end class
