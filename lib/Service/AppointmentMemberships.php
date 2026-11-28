<?php

/**
 * Decidiq AppointmentMemberships.
 *
 * Materializes the memberships and position holds an enacted appointment creates.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;

/**
 * One membership per candidate, and a position hold where the appointment names a position.
 *
 * Split out of DecisionLifecycleService, which calls it once an adopted
 * appointment is enacted.
 *
 * @spec openspec/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class AppointmentMemberships {

	/**
	 * Writes the position hold of each appointed membership that fills a position.
	 *
	 * @var AppointmentPositionHolds
	 */
	private readonly AppointmentPositionHolds $positionHolds;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService   OpenRegister object store.
	 * @param LoggerInterface         $logger          Records a materialization that failed.
	 * @param DecisionContextResolver $contextResolver Resolves relation values to ids.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly DecisionContextResolver $contextResolver,
	) {
		$this->positionHolds = new AppointmentPositionHolds(objectService: $objectService, logger: $logger);

	}//end __construct()

	/**
	 * Materialize one Membership per candidate when an appointment decision
	 * is adopted and enacted, and write the created ids back onto the
	 * decision's `appointedMemberships` field.
	 *
	 * Each Membership gets `role=targetRole`, `startDate=enactedAt`,
	 * `governanceBody=targetBody` when resolvable, and either `person` (when
	 * the candidate carries one) or `label=externalName` for a
	 * not-yet-registered candidate. The paired position (design.md D1: none
	 * when `targetPosts` is empty, the one entry shared by every candidate
	 * when it has exactly one, else the position at
	 * `index % count($targetPosts)` — a no-op modulo once
	 * DecisionLifecycleService's enact-time pairing gate has confirmed the
	 * lengths match) is no longer a
	 * `post` on the membership: it becomes a `position-hold` on that
	 * membership, from the enactment day, appointed by this decision
	 * (configurable-types-domain-model, REQ-CTM-006). Absent
	 * fields are dropped rather than written as null (`array_filter`),
	 * matching how every other guarded write in the lifecycle only sets
	 * what it knows.
	 *
	 * Idempotency guard: skips when `appointedMemberships` is already
	 * non-empty — `enacted` is not re-enterable through the transition
	 * guard, so this is a defensive second layer (design.md Risk 2), not the
	 * primary protection.
	 *
	 * Fail-soft, matching generateResolutionRecord(): the lifecycle write
	 * already persisted, so a materialization failure is logged loudly and
	 * never rolls the transition back.
	 *
	 * @param array<string, mixed> $decision Decision object array (post-transition)
	 * @param string $decisionId UUID of the enacted decision
	 *
	 * @spec openspec/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 *
	 * @return void
	 */
	public function materialize(array $decision, string $decisionId): void {
		$candidates = (array)($decision['candidates'] ?? []);
		if ($candidates === [] || (array)($decision['appointedMemberships'] ?? []) !== []) {
			return;
		}

		try {
			$targetPosts = (array)($decision['targetPosts'] ?? []);
			$targetRole = (string)($decision['targetRole'] ?? '');
			$targetBody = $this->contextResolver->resolveRelationId(value: ($decision['targetBody'] ?? null));
			$enactedAt = (string)($decision['enactedAt'] ?? '');

			$membershipIds = [];
			foreach (array_values($candidates) as $index => $candidate) {
				$candidate = (array)$candidate;

				// D1 pairing: an empty `targetPosts` never has index 0, so the
				// null-coalesce below is what makes the empty case a role-only
				// Membership (no position hold) without a separate branch for it.
				$position = $this->contextResolver->resolveRelationId(
					value: ($targetPosts[($index % max(count($targetPosts), 1))] ?? null)
				);

				$personId = $this->contextResolver->resolveRelationId(value: ($candidate['person'] ?? null));

				$label = null;
				if ($personId === null) {
					$label = (string)($candidate['externalName'] ?? '');
				}

				$membership = array_filter(
					[
						'role' => $targetRole,
						'startDate' => $enactedAt,
						'governanceBody' => $targetBody,
						'person' => $personId,
						'label' => $label,
					],
					static fn (mixed $value): bool => $value !== null
				);

				$created = $this->objectService->saveObject(
					object: $membership,
					options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'membership'))
				);

				$membershipId = (string)($created->jsonSerialize()['id'] ?? '');
				$membershipIds[] = $membershipId;

				$this->positionHolds->write(
					membershipId: $membershipId,
					positionId: $position,
					appointment: ['body' => $targetBody, 'startDate' => $enactedAt, 'decision' => $decisionId]
				);
			}

			// The appointed memberships go back onto the decision they came from.
			$decisionTarget = new ObjectFindTarget(register: 'decidiq', schema: 'decision');
			$decision['appointedMemberships'] = $membershipIds;
			$this->objectService->saveObject(object: $decision, options: new ObjectSaveOptions(target: $decisionTarget, uuid: $decisionId));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: decision enacted but appointment Membership materialization failed',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end materialize()
}//end class
