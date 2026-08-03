<?php
/**
 * Decidesk Governance Role Projection Listener
 *
 * Keeps each GovernanceBody's OpenRegister RBAC scopes in sync with its roster:
 * on any Participant/Membership create/update/delete, it reconciles the
 * affected body's `chair` + `signatory` scopes via GovernanceRoleScopeProjector
 * (consume-or-rbac-authorization, REQ-RBAC-001). Fail-soft: a projection error
 * never breaks the object write path, and because the RBAC rule fails closed a
 * missed reconcile can only ever deny, never over-grant.
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Listener;

use OCA\Decidesk\Service\GovernanceRoleScopeProjector;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Reconciles a body's OR RBAC scopes on Participant/Membership writes.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 */
class GovernanceRoleProjectionListener implements IEventListener
{

    /**
     * Schema slugs whose writes change a body's signatory roster.
     *
     * @var string[]
     */
    private const ROSTER_SCHEMAS = ['participant', 'membership'];

    /**
     * Constructor.
     *
     * @param GovernanceRoleScopeProjector $projector Scope projector
     * @param LoggerInterface              $logger    Diagnostic logger
     */
    public function __construct(
        private readonly GovernanceRoleScopeProjector $projector,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a Participant/Membership OR write event by reconciling scopes.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false
            && ($event instanceof ObjectUpdatedEvent) === false
            && ($event instanceof ObjectDeletedEvent) === false
        ) {
            return;
        }

        try {
            $entity = $event->getObject();
            if ($entity === null) {
                return;
            }

            $row  = $this->extractRow(entity: $entity);
            $slug = $this->resolveSchemaSlug(entity: $entity, row: $row);
            if (in_array($slug, self::ROSTER_SCHEMAS, true) === false) {
                return;
            }

            $this->projector->reconcileFromMemberRow($row);
        } catch (\Throwable $e) {
            // Fail soft: scope projection must never break the object write path.
            $this->logger->warning(
                'Decidesk: governance role projection listener failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end handle()

    /**
     * Extract the serialised payload row from an OR object entity.
     *
     * Prefers the entity's own `getObject()` map and falls back to
     * `jsonSerialize()` when that yields nothing.
     *
     * @param object $entity OR object entity
     *
     * @return array<string, mixed> Serialised payload, empty when unavailable
     *
     * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
     */
    private function extractRow(object $entity): array
    {
        $row = [];
        if (method_exists($entity, 'getObject') === true) {
            $row = (array) $entity->getObject();
        }

        if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
            $row = (array) $entity->jsonSerialize();
        }

        return $row;
    }//end extractRow()

    /**
     * Resolve the schema slug from the OR entity surface.
     *
     * @param object               $entity OR object entity
     * @param array<string, mixed> $row    Serialised payload
     *
     * @return string Schema slug (lower-cased), or '' when unresolvable
     */
    private function resolveSchemaSlug(object $entity, array $row): string
    {
        $candidates = [
            ($row['_schemaSlug'] ?? null),
            ($row['_schema'] ?? null),
            ($row['schema'] ?? null),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) === true && $candidate !== '') {
                return strtolower($candidate);
            }
        }

        if (method_exists($entity, 'getSchemaSlug') === true) {
            $slug = $entity->getSchemaSlug();
            if (is_string($slug) === true && $slug !== '') {
                return strtolower($slug);
            }
        }

        return '';
    }//end resolveSchemaSlug()
}//end class
