<?php

/**
 * Decidiq ConfidentialityLifter
 *
 * Lifts a confidentiality restriction on the lifting decision of the body:
 * records the decision, the date and optional conditions, moves the
 * restriction to dissolved and returns the target's classifier to its
 * non-public default. Nothing is published: the target goes back through the
 * normal publication flow.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Listener\EntityPayloadReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the lifting of a restriction and reopens its target to staff.
 *
 * Access control (OWASP A01): the restriction is read with the caller's own
 * rights (404 when they cannot), then the caller must hold the imposing
 * body's authority, as for imposing. The restriction and the target are
 * then written as the system: the register lets only administrators update
 * a restriction, and the lifting follows from the body's decision.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
 */
class ConfidentialityLifter {

	/**
	 * The schema of a restriction.
	 *
	 * @var string
	 */
	private const SCHEMA = 'confidentiality-restriction';

	/**
	 * Restriction states that still close their target.
	 *
	 * @var list<string>
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Per scope: the target field and the value it returns to on lifting.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const REOPENED = [
		'item'     => ['confidentiality', 'internal'],
		'decision' => ['isPublished', 'internal'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param ConfidentialityImposer $imposer       Who holds the imposing body's authority.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        Logs a classifier that could not be reopened.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ConfidentialityImposer $imposer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lift a restriction.
	 *
	 * Parameters: `dissolutionDecision` (the lifting decision, required) and
	 * `liftingConditions` (optional free text).
	 *
	 * @param string               $userId        The caller.
	 * @param string               $restrictionId The restriction.
	 * @param array<string, mixed> $params        The request parameters.
	 *
	 * @return array{restriction: array<string, mixed>, classifier: string} The
	 *     stored restriction, and `reopened`, `not-needed`, `still-restricted` or `not-updated` for the target.
	 *
	 * @throws ConfidentialityImposeRefusedException On a refusal (400, 403, 404, 409 or 422).
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-recorded-with-conditions
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function lift(string $userId, string $restrictionId, array $params): array {
		$restriction = $this->readable(id: $restrictionId);

		if ($this->imposer->mayImpose(userId: $userId, bodyId: (string)($restriction['imposedByBody'] ?? '')) === false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'Only the chair, vice-chair or secretary of the imposing body, the secretariat or an administrator may lift this restriction.',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$request = self::request(params: $params);
		$this->requireLiftable(restriction: $restriction);
		$this->requireDecision(decisionId: $request['dissolutionDecision']);

		$lifted = array_merge(
			$restriction,
			[
				'lifecycle'           => 'dissolved',
				'dissolutionDecision' => $request['dissolutionDecision'],
				'liftingDate'         => $this->time->getDateTime()->format('Y-m-d'),
				'awaitingPublication' => self::awaitsPublication(restriction: $restriction),
			]
		);
		if ($request['liftingConditions'] !== null) {
			$lifted['liftingConditions'] = $request['liftingConditions'];
		}

		$saved = $this->objectService->saveObject(
			object: $lifted,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: self::SCHEMA), uuid: $restrictionId),
			_rbac: false,
			_multitenancy: false
		);

		return [
			'restriction' => (new EntityPayloadReader())->payloadOf(entity: $saved),
			'classifier'  => $this->reopenTarget(restriction: $lifted, restrictionId: $restrictionId),
		];
	}//end lift()

	/**
	 * The validated request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{dissolutionDecision: string, liftingConditions: string|null}
	 *
	 * @throws ConfidentialityImposeRefusedException With 400 when the decision is missing.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public static function request(array $params): array {
		$decision = ($params['dissolutionDecision'] ?? null);
		if (is_string($decision) === false || trim($decision) === '') {
			throw new ConfidentialityImposeRefusedException(
				message: 'A restriction is lifted only on a lifting decision. Name the decision.',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		$conditions = ($params['liftingConditions'] ?? null);
		if (is_string($conditions) === true) {
			$conditions = trim($conditions);
		}

		if (is_string($conditions) === false || $conditions === '') {
			$conditions = null;
		}

		return [
			'dissolutionDecision' => trim($decision),
			'liftingConditions'   => $conditions,
		];
	}//end request()

	/**
	 * The restriction, read with the caller's rights.
	 *
	 * @param string $id The restriction.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ConfidentialityImposeRefusedException With 404.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	private function readable(string $id): array {
		try {
			$restriction = $this->load(id: $id, schema: self::SCHEMA, asCaller: true);
		} catch (Throwable) {
			// OpenRegister answers an object the caller may not read with an exception or with null.
			$restriction = null;
		}

		if ($restriction === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The restriction was not found.', status: Http::STATUS_NOT_FOUND);
		}

		return $restriction;
	}//end readable()

	/**
	 * Refuse a restriction that is no longer active, or one whose ground needs ratification first.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return void
	 *
	 * @throws ConfidentialityImposeRefusedException With 409.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-lifecycle-transitions-are-guarded-declaratively
	 */
	private function requireLiftable(array $restriction): void {
		$state = (string)($restriction['lifecycle'] ?? '');
		if (in_array($state, self::ACTIVE_STATES, true) === false) {
			throw new ConfidentialityImposeRefusedException(message: 'This restriction is no longer in force.', status: Http::STATUS_CONFLICT);
		}

		if ($state !== 'imposed') {
			return;
		}

		// Read as the system and fail closed, as LiftAfterRatificationGuard does on the save.
		try {
			$ground = $this->load(id: (string)($restriction['ground'] ?? ''), schema: 'confidentiality-ground', asCaller: false);
		} catch (Throwable) {
			$ground = null;
		}

		if ($ground === null || ($ground['requiresRatification'] ?? true) !== false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'This ground needs ratification by the body before the restriction can be lifted.',
				status: Http::STATUS_CONFLICT
			);
		}
	}//end requireLiftable()

	/**
	 * Refuse a lifting decision that does not exist.
	 *
	 * @param string $decisionId The decision.
	 *
	 * @return void
	 *
	 * @throws ConfidentialityImposeRefusedException With 422.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	private function requireDecision(string $decisionId): void {
		try {
			$decision = $this->load(id: $decisionId, schema: 'decision', asCaller: true);
		} catch (Throwable) {
			$decision = null;
		}

		if ($decision === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The lifting decision was not found.');
		}
	}//end requireDecision()

	/**
	 * Whether a lifted restriction waits for its target to be published.
	 *
	 * An agenda item or decision goes back to internal and waits for the normal
	 * publication run; a document is not reopened by lifting, so it waits for
	 * nothing here. ConfidentialityPublishedListener clears the flag.
	 *
	 * @param array<string, mixed> $restriction The restriction being lifted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public static function awaitsPublication(array $restriction): bool {
		return isset(self::REOPENED[(string)($restriction['scope'] ?? '')]);
	}//end awaitsPublication()

	/**
	 * Return the target's classifier to internal, unless another restriction still closes it.
	 *
	 * Never to public: the target goes through the normal publication flow.
	 *
	 * @param array<string, mixed> $restriction   The lifted restriction.
	 * @param string               $restrictionId Its id.
	 *
	 * @return string `reopened`, `not-needed`, `still-restricted` or `not-updated`.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-never-auto-publishes
	 */
	private function reopenTarget(array $restriction, string $restrictionId): string {
		$scope = (string)($restriction['scope'] ?? '');
		if (isset(self::REOPENED[$scope]) === false) {
			return 'not-needed';
		}

		[$field, $value] = self::REOPENED[$scope];
		$targetField     = ConfidentialityImposer::TARGETS[$scope]['field'];
		$schema          = ConfidentialityImposer::TARGETS[$scope]['schema'];
		$targetId        = (string)($restriction[$targetField] ?? '');
		try {
			if ($this->otherActive(scope: $scope, targetField: $targetField, targetId: $targetId, restrictionId: $restrictionId) === true) {
				return 'still-restricted';
			}

			$target = $this->load(id: $targetId, schema: $schema, asCaller: false);
			if ($target === null) {
				return 'not-updated';
			}

			if (($target[$field] ?? null) !== 'confidential') {
				return 'not-needed';
			}

			$this->objectService->saveObject(
				object: array_merge($target, [$field => $value]),
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: $schema), uuid: $targetId),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// Fail closed: the target stays confidential.
			$this->logger->warning('Decidiq: the classifier of a lifted restriction\'s target was not reopened', ['exception' => $e->getMessage()]);
			return 'not-updated';
		}

		return 'reopened';
	}//end reopenTarget()

	/**
	 * Whether another active restriction targets the same object.
	 *
	 * @param string $scope         The scope.
	 * @param string $targetField   The restriction field naming the target.
	 * @param string $targetId      The target.
	 * @param string $restrictionId The restriction being lifted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-never-auto-publishes
	 */
	private function otherActive(string $scope, string $targetField, string $targetId, string $restrictionId): bool {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA, 'scope' => $scope, $targetField => $targetId]],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($rows['results'] ?? $rows) as $row) {
			$data = (new EntityPayloadReader())->payloadOf(entity: $row);
			$id   = (string)($data['id'] ?? '');
			if ($id === $restrictionId || (string)($data[$targetField] ?? '') !== $targetId) {
				continue;
			}

			if (in_array(($data['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === true) {
				return true;
			}
		}

		return false;
	}//end otherActive()

	/**
	 * An object's data, or null when it does not exist (or, as the caller, cannot be read).
	 *
	 * @param string $id       The object id.
	 * @param string $schema   The schema slug.
	 * @param bool   $asCaller True to read with the caller's rights, false as the system.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $id, string $schema, bool $asCaller): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(
			id: $id,
			options: new ObjectFindOptions(register: 'decidiq', schema: $schema),
			_rbac: $asCaller,
			_multitenancy: $asCaller
		);
		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end load()
}//end class
