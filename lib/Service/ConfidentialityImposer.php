<?php

/**
 * Decidiq ConfidentialityImposer
 *
 * Imposes a confidentiality restriction on one paper, agenda item or
 * decision: checks that the caller holds the imposing body's authority,
 * that the legal ground is a selectable configured ground and that the
 * caller can read the target, then records the restriction and drives the
 * target's existing classifier.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
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
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records an imposed confidentiality restriction and closes its target.
 *
 * Access control (OWASP A01): the caller must be an administrator, in the
 * secretariat, or in the imposing body's signatory scope (chair, vice-chair,
 * secretary). That check runs before any object is read, so a refused caller
 * learns nothing about the target. The target is then read with the caller's
 * own object rights: a target they cannot read answers 404.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityImposer {

	/**
	 * Per scope: the target's schema and the restriction field that refers to it.
	 *
	 * @var array<string, array{schema: string, field: string}>
	 */
	public const TARGETS = [
		'document' => ['schema' => 'digital-document', 'field' => 'targetDocument'],
		'item'     => ['schema' => 'agenda-item', 'field' => 'targetAgendaItem'],
		'decision' => ['schema' => 'decision', 'field' => 'targetDecision'],
	];

	/**
	 * Per scope: the target field and value that close it. A paper has no
	 * classifier of its own: every public surface reads the restriction.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const CLASSIFIERS = [
		'item'     => ['confidentiality', 'confidential'],
		'decision' => ['isPublished', 'confidential'],
	];

	/**
	 * Which organ may impose.
	 *
	 * @var list<string>
	 */
	public const IMPOSING_ORGANS = ['body', 'chair', 'executive-board'];

	/**
	 * Groups that may impose for any body.
	 *
	 * @var list<string>
	 */
	private const STAFF_GROUPS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param GovernanceScopeGuard   $scopeGuard    The imposing body's signatory scope.
	 * @param IGroupManager          $groupManager  Administrator and secretariat membership.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        Logs a classifier that could not be driven.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly GovernanceScopeGuard $scopeGuard,
		private readonly IGroupManager $groupManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Impose a restriction.
	 *
	 * Parameters: `scope` (document, item or decision), `target` (its id),
	 * `ground` (a confidentiality-ground id), `imposedBy` (body, chair or
	 * executive-board) and `imposedByBody` (the governance body).
	 *
	 * @param string               $userId The caller.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{restriction: array<string, mixed>, classifier: string} The
	 *     stored restriction, and `closed`, `not-needed` or `not-updated` for the target's classifier.
	 *
	 * @throws ConfidentialityImposeRefusedException On a refusal (400, 403, 404 or 422).
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-college-imposes-geheimhouding-on-a-document-for-the-raad
	 */
	public function impose(string $userId, array $params): array {
		$request = self::request(params: $params);

		if ($this->mayImpose(userId: $userId, bodyId: $request['imposedByBody']) === false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'Only the chair, vice-chair or secretary of the imposing body, the secretariat or an administrator may impose confidentiality.',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$this->requireSelectableGround(groundId: $request['ground']);
		$this->requireReadableTarget(scope: $request['scope'], targetId: $request['target']);

		$restriction = $this->record(request: $request);

		return [
			'restriction' => $restriction,
			'classifier'  => $this->closeTarget(scope: $request['scope'], targetId: $request['target']),
		];
	}//end impose()

	/**
	 * The validated request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{scope: string, target: string, ground: string, imposedBy: string, imposedByBody: string}
	 *
	 * @throws ConfidentialityImposeRefusedException With 400 when a value is missing or unknown.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public static function request(array $params): array {
		$request = [];
		foreach (['scope', 'target', 'ground', 'imposedBy', 'imposedByBody'] as $key) {
			$value = ($params[$key] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				throw new ConfidentialityImposeRefusedException(
					message: 'Missing ' . $key . '. A restriction needs a scope, a target, a ground, the imposing organ and its body.',
					status: Http::STATUS_BAD_REQUEST
				);
			}

			$request[$key] = trim($value);
		}

		if (isset(self::TARGETS[$request['scope']]) === false) {
			throw new ConfidentialityImposeRefusedException(message: 'The scope must be document, item or decision.', status: Http::STATUS_BAD_REQUEST);
		}

		if (in_array($request['imposedBy'], self::IMPOSING_ORGANS, true) === false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'The imposing organ must be body, chair or executive-board.',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return $request;
	}//end request()

	/**
	 * Whether the caller may impose for this body.
	 *
	 * @param string $userId The caller.
	 * @param string $bodyId The imposing body.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function mayImpose(string $userId, string $bodyId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return $this->scopeGuard->isInBodyScope(userId: $userId, bodyId: $bodyId, scope: GovernanceScopeGuard::SCOPE_SIGNATORY);
	}//end mayImpose()

	/**
	 * Refuse a ground that does not exist or is no longer selectable.
	 *
	 * @param string $groundId The ground.
	 *
	 * @return void
	 *
	 * @throws ConfidentialityImposeRefusedException With 422.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-002-configurable-ground-list-with-dual-gemeentewet-article-labels
	 */
	private function requireSelectableGround(string $groundId): void {
		$ground = $this->load(id: $groundId, schema: 'confidentiality-ground', asCaller: false);
		if ($ground === null) {
			throw new ConfidentialityImposeRefusedException(message: 'No such ground. A restriction needs one of the configured legal grounds.');
		}

		if (($ground['active'] ?? true) === false) {
			throw new ConfidentialityImposeRefusedException(message: 'This ground is no longer in use. Choose another ground.');
		}
	}//end requireSelectableGround()

	/**
	 * Refuse a target the caller cannot read.
	 *
	 * @param string $scope    The scope.
	 * @param string $targetId The target.
	 *
	 * @return void
	 *
	 * @throws ConfidentialityImposeRefusedException With 404.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	private function requireReadableTarget(string $scope, string $targetId): void {
		try {
			$target = $this->load(id: $targetId, schema: self::TARGETS[$scope]['schema'], asCaller: true);
		} catch (Throwable) {
			// OpenRegister answers an object the caller may not read with an exception or with null.
			$target = null;
		}

		if ($target === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The object to restrict was not found.', status: Http::STATUS_NOT_FOUND);
		}
	}//end requireReadableTarget()

	/**
	 * Store the restriction, imposed now.
	 *
	 * @param array{scope: string, target: string, ground: string, imposedBy: string, imposedByBody: string} $request The request.
	 *
	 * @return array<string, mixed> The stored restriction.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	private function record(array $request): array {
		$restriction = [
			'scope'                                     => $request['scope'],
			self::TARGETS[$request['scope']]['field']   => $request['target'],
			'ground'                                    => $request['ground'],
			'imposedBy'                                 => $request['imposedBy'],
			'imposedByBody'                             => $request['imposedByBody'],
			'imposedAt'                                 => $this->time->getDateTime()->format(DATE_ATOM),
			'lifecycle'                                 => 'imposed',
		];

		// The caller's own rights: the register lets every signed-in user
		// create a restriction, and the authority check above is narrower.
		$saved = $this->objectService->saveObject(
			object: $restriction,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'confidentiality-restriction'))
		);

		return (new EntityPayloadReader())->payloadOf(entity: $saved);
	}//end record()

	/**
	 * Set the target's existing classifier to confidential, every other field kept.
	 *
	 * Runs after the restriction is stored, so it reads the target as the
	 * restriction's own listeners left it. It runs as the system: the
	 * decision's publication state is closed to direct updates, and closing
	 * follows from the restriction, not from the caller's rights. A failure
	 * is reported, not raised: the restriction already keeps the target off
	 * every public surface.
	 *
	 * @param string $scope    The scope.
	 * @param string $targetId The target.
	 *
	 * @return string `closed`, `not-needed` (a paper, or already closed) or `not-updated`.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-imposing-on-an-agenda-item-drives-the-existing-confidentiality-enum
	 */
	private function closeTarget(string $scope, string $targetId): string {
		if (isset(self::CLASSIFIERS[$scope]) === false) {
			return 'not-needed';
		}

		[$field, $value] = self::CLASSIFIERS[$scope];
		$schema          = self::TARGETS[$scope]['schema'];
		try {
			$target = $this->load(id: $targetId, schema: $schema, asCaller: false);
			if ($target === null) {
				return 'not-updated';
			}

			if (($target[$field] ?? null) === $value) {
				return 'not-needed';
			}

			$this->objectService->saveObject(
				object: array_merge($target, [$field => $value]),
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: $schema), uuid: $targetId),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the classifier of a restricted object was not updated', ['exception' => $e->getMessage()]);
			return 'not-updated';
		}

		return 'closed';
	}//end closeTarget()

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
