<?php

/**
 * Decidiq Portal Create Open-Parent Guard Listener
 *
 * Fail-closed enforcement of the citizen create-actions' open-parent
 * constraint (portal-citizen-create-actions, REQ-DKPCA-001/002): a
 * `createReaction` may only land on a `PublicConsultation` whose `status` is
 * `open`, and a `createBudgetProposal` only on a `ParticipatoryBudget` whose
 * `status` is `submission`. Portaliq's shared create receiver
 * (`ContributionController::create()` + `PortalObjectWriter::createObject()`,
 * contract v2.2) stamps the scope field + `defaults` but does NOT read or
 * enforce a declared `parentConstraint` (verified against portaliq at HEAD) —
 * so Decidiq enforces it itself, at the OpenRegister insert boundary, via
 * `ObjectCreatingEvent`. That event implements `StoppableEventInterface`:
 * `stopPropagation()` makes `MagicMapper::insertObjectEntity()` throw a
 * `HookStoppedException` BEFORE the row is persisted (verified against
 * OpenRegister's MagicMapper at HEAD) — a true fail-closed reject, not a
 * compensating after-the-fact cleanup.
 *
 * SCHEMA IDENTIFICATION CAVEAT: `ObjectCreatingEvent` fires on the raw,
 * pre-render `ObjectEntity` — `getObject()` returns only the schema's own
 * business-data JSON (no `_schemaSlug`/`@self` envelope; that is added by the
 * read-side renderer), and `getRegister()`/`getSchema()` return the
 * register/schema's numeric database id, not their slugs (verified against
 * `ObjectEntity`/`MagicMapper` at HEAD: `(int) $entity->getSchema()` is used
 * to look the row up by id via the schema mapper). This listener therefore
 * tries schema identification in TWO tiers: (1) the same `_schemaSlug` /
 * `_schema` / `schema` row-key + `getSchemaSlug()`/`getSchema()` method
 * candidates `SubmissionDeadlineListener`/`MeetingFolderListener` already use
 * elsewhere in this codebase (free when a caller happens to stamp one), then
 * (2) a fallback on the two owned schemas' REQUIRED, jointly-distinctive
 * field signature (verified against `decidesk_register.json`):
 * `consultation-reaction` rows always carry `moderationStatus` +
 * `submitterId` + `body`; `budget-proposal` rows always carry `submitter` +
 * `requestedAmount` + `status`. Both signatures hold on EVERY existing write
 * path for these schemas — the new portaliq create actions AND Decidiq's own
 * `ReactionIntakeService::submitReaction()` / `BudgetVotingService::submitProposal()`
 * — so tier (2) is what actually makes this guard fire in practice today (no
 * caller currently stamps a `_schemaSlug` on these two schemas), and it fires
 * for every create of either schema, not only portal-originated ones: a
 * deliberately stricter, defence-in-depth posture (design.md "Open
 * questions", apply-time resolution of Open Q1).
 *
 * The open-parent constraint itself is read from
 * `PortalContributionProvider`'s own manifest (`parentConstraint` on each
 * `type: create` action) rather than duplicated here, so the manifest stays
 * the single declarative source of truth for both what portaliq is told and
 * what Decidiq enforces.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Portal\PortalContributionProvider;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rejects a citizen `createReaction` / `createBudgetProposal` write whose
 * parent is not open, before the row is ever persisted.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 */
class PortalCreateOpenParentGuardListener implements IEventListener {

	/**
	 * The register slug every guarded schema lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema slug this listener recognises as a consultation reaction.
	 *
	 * @var string
	 */
	private const SCHEMA_CONSULTATION_REACTION = 'consultation-reaction';

	/**
	 * The schema slug this listener recognises as a budget proposal.
	 *
	 * @var string
	 */
	private const SCHEMA_BUDGET_PROPOSAL = 'budget-proposal';

	/**
	 * OpenRegister's object service FQCN (lazily resolved from the container).
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService resolution).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister lifecycle event; reject a guarded create whose
	 * parent does not satisfy its declared constraint.
	 *
	 * @param Event $event The event to handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		try {
			$this->evaluate(event: $event);
		} catch (\Throwable $e) {
			// A resolution error must never silently ALLOW a guarded write
			// through: reject closed rather than fail open on the two schemas
			// this listener owns. Unrelated schemas never reach this catch
			// block (evaluate() already returns before any lookup runs when
			// neither tier identifies the row as one of the two schemas).
			$this->logger->warning(
				'Decidiq: portal create open-parent guard failed, rejecting closed',
				['exception' => $e->getMessage()]
			);
			$event->setErrors(['message' => 'Could not verify the parent is open']);
			$event->stopPropagation();
		}

	}//end handle()

	/**
	 * Identify the row, resolve its declared parent constraint, and reject
	 * the create when the parent does not satisfy it. Returns early (a no-op)
	 * for any row that is not one of the two owned schemas, or that declares
	 * no `parentConstraint`.
	 *
	 * @param ObjectCreatingEvent $event The event to evaluate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function evaluate(ObjectCreatingEvent $event): void {
		$entity = $event->getObject();
		if (is_object($entity) === false) {
			return;
		}

		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		$schema = $this->schemaSlugFromRow(row: $row);
		if ($schema === '') {
			$schema = $this->schemaSlugFromEntity(entity: $entity);
		}

		if (in_array($schema, [self::SCHEMA_CONSULTATION_REACTION, self::SCHEMA_BUDGET_PROPOSAL], true) === false) {
			$schema = $this->detectSchemaBySignature(row: $row);
		}

		if ($schema === '') {
			return;
		}

		$constraint = $this->parentConstraintFor(schema: $schema);
		if ($constraint === null) {
			return;
		}

		$parentId = $this->resolveParentId(row: $row, constraint: $constraint);
		$satisfied = ($parentId !== '' && $this->parentSatisfiesConstraint(parentId: $parentId, constraint: $constraint) === true);
		if ($satisfied === false) {
			$this->reject(event: $event, schema: $schema, constraint: $constraint);
		}

	}//end evaluate()

	/**
	 * Reject the create: set a descriptive error and stop propagation so
	 * `MagicMapper::insertObjectEntity()` throws before the row is persisted.
	 *
	 * @param ObjectCreatingEvent $event The event to stop.
	 * @param string $schema The recognised schema slug.
	 * @param array<string, mixed> $constraint The unmet parent constraint.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function reject(ObjectCreatingEvent $event, string $schema, array $constraint): void {
		$event->setErrors(
			[
				'message' => sprintf(
					"%s requires the parent %s to have %s == '%s'",
					$schema,
					(string)$constraint['parentSchema'],
					(string)$constraint['statusField'],
					(string)$constraint['statusValue']
				),
			]
		);
		$event->stopPropagation();

	}//end reject()

	/**
	 * Tier 1a: resolve the schema slug from the raw object payload's own
	 * schema-hint keys (the same row-key candidates
	 * `SubmissionDeadlineListener`/`MeetingFolderListener` use for
	 * `ObjectCreatingEvent`/`ObjectCreatedEvent` elsewhere in this codebase).
	 * Returns '' when none resolve, in which case the caller falls back to
	 * {@see detectSchemaBySignature()}.
	 *
	 * @param array<string, mixed> $row Serialized payload.
	 *
	 * @return string Schema slug, or '' when unresolvable.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function schemaSlugFromRow(array $row): string {
		$candidates = [
			$row['_schemaSlug'] ?? null,
			$row['_schema'] ?? null,
			$row['schema'] ?? null,
		];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return strtolower($candidate);
			}
		}

		return '';
	}//end schemaSlugFromRow()

	/**
	 * Tier 1b: resolve the schema slug from the entity's own accessor methods
	 * (matches `SubmissionDeadlineListener`/`MeetingFolderListener`'s
	 * fallback). Note: the REAL `ObjectEntity::getSchema()` returns the
	 * schema's numeric database id, not its slug (verified against
	 * `MagicMapper` at HEAD), so in practice this tier only ever resolves via
	 * a (currently hypothetical) `getSchemaSlug()` method; it is kept for
	 * forward-compatibility and codebase consistency, not because it fires
	 * today.
	 *
	 * @param object $entity The OR object entity.
	 *
	 * @return string Schema slug, or '' when unresolvable.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function schemaSlugFromEntity(object $entity): string {
		if (method_exists($entity, 'getSchemaSlug') === true) {
			$slug = $entity->getSchemaSlug();
			if (is_string($slug) === true && $slug !== '') {
				return strtolower($slug);
			}
		}

		if (method_exists($entity, 'getSchema') === true) {
			$schema = $entity->getSchema();
			if (is_string($schema) === true && $schema !== '') {
				return strtolower($schema);
			}
		}

		return '';
	}//end schemaSlugFromEntity()

	/**
	 * Tier 2: identify whether a row is a `consultation-reaction` or
	 * `budget-proposal` create by its required, jointly-distinctive field
	 * signature (see class docblock for why schema slugs are usually
	 * unavailable at this lifecycle point).
	 *
	 * @param array<string, mixed> $row The raw (pre-render) object data.
	 *
	 * @return string The recognised schema slug, or '' when neither matches.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function detectSchemaBySignature(array $row): string {
		if (array_key_exists('moderationStatus', $row) === true
			&& array_key_exists('submitterId', $row) === true
			&& array_key_exists('body', $row) === true
		) {
			return self::SCHEMA_CONSULTATION_REACTION;
		}

		if (array_key_exists('submitter', $row) === true
			&& array_key_exists('requestedAmount', $row) === true
			&& array_key_exists('status', $row) === true
		) {
			return self::SCHEMA_BUDGET_PROPOSAL;
		}

		return '';
	}//end detectSchemaBySignature()

	/**
	 * Resolve the declared `parentConstraint` for a schema from
	 * `PortalContributionProvider`'s own `citizen` manifest (single source of
	 * truth for both what portaliq is told and what this listener enforces).
	 *
	 * @param string $schema The child schema slug.
	 *
	 * @return array<string, mixed>|null The constraint, or null when undeclared.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function parentConstraintFor(string $schema): ?array {
		$manifest = (new PortalContributionProvider())->getContribution(['audience' => 'citizen']);
		foreach ((array)($manifest['actions'] ?? []) as $action) {
			if (($action['schema'] ?? '') === $schema && isset($action['parentConstraint']) === true) {
				return (array)$action['parentConstraint'];
			}
		}

		return null;
	}//end parentConstraintFor()

	/**
	 * Resolve the parent id from either the scalar reference field (the
	 * portaliq create action's shape) or the generic `relations` array
	 * (Decidiq's own `ReactionIntakeService`/`BudgetVotingService` shape).
	 *
	 * @param array<string, mixed> $row The raw object data.
	 * @param array<string, mixed> $constraint The resolved parent constraint.
	 *
	 * @return string The parent id, or '' when unresolvable.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function resolveParentId(array $row, array $constraint): string {
		$field = (string)($constraint['field'] ?? '');
		if ($field !== '' && is_string($row[$field] ?? null) === true && (string)$row[$field] !== '') {
			return (string)$row[$field];
		}

		$parentSchema = (string)($constraint['parentSchema'] ?? '');
		foreach ((array)($row['relations'] ?? []) as $relation) {
			if (is_array($relation) === true
				&& ($relation['schema'] ?? '') === $parentSchema
				&& isset($relation['id']) === true
			) {
				return (string)$relation['id'];
			}
		}

		return '';
	}//end resolveParentId()

	/**
	 * Fetch the parent object and confirm it satisfies the constraint's
	 * status field/value. Fails closed (false) on any lookup error or a
	 * missing parent.
	 *
	 * @param string $parentId The parent object id.
	 * @param array<string, mixed> $constraint The resolved parent constraint.
	 *
	 * @return bool True only when the parent exists and matches.
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function parentSatisfiesConstraint(string $parentId, array $constraint): bool {
		$objectService = $this->container->get(self::OBJECT_SERVICE);

		$parentSchema = (string)($constraint['parentSchema'] ?? '');
		$parentEntity = $objectService->find(id: $parentId, register: self::REGISTER, schema: $parentSchema);
		if ($parentEntity === null) {
			return false;
		}

		$parent = [];
		if (method_exists($parentEntity, 'jsonSerialize') === true) {
			$parent = (array)$parentEntity->jsonSerialize();
		} elseif (method_exists($parentEntity, 'getObject') === true) {
			$parent = (array)$parentEntity->getObject();
		}

		$statusField = (string)($constraint['statusField'] ?? 'status');
		$statusValue = (string)($constraint['statusValue'] ?? '');

		return (string)($parent[$statusField] ?? '') === $statusValue;
	}//end parentSatisfiesConstraint()
}//end class
