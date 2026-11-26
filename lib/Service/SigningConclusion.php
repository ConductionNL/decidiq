<?php

/**
 * Decidiq Signing Conclusion
 *
 * Records how filinq concluded a signing request decidiq raised, on the
 * signed record and on its method=signature stages. The record is matched
 * on the request id it went out under, so an answer for an earlier round of
 * the same record changes nothing (design D2). `signed` links the signed
 * document, marks the signers and resolves the stages; `declined`,
 * `expired` and `cancelled` mark the round failed and leave the stages
 * unresolved with the reason.
 *
 * Writes run in system context: filinq concludes a request in the last
 * signer's session (or a job), and that signer need not be allowed to edit
 * the record.
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
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Record filinq's signing outcome on the signed record and its stages.
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 */
class SigningConclusion {

	/**
	 * The outcomes filinq reports that are not a signature.
	 *
	 * @var list<string>
	 */
	public const UNSIGNED = ['declined', 'expired', 'cancelled'];

	/**
	 * The schemas a signing round runs on: minutes, a meeting's decision
	 * list and a motion (SigningRoundServiceInterface::SUBJECTS).
	 *
	 * @var list<string>
	 */
	private const SCHEMAS = ['minutes', 'meeting', 'decision'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param EIDASSignatureService  $signatures    Resolves the signature stages
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly EIDASSignatureService $signatures,
	) {
	}//end __construct()

	/**
	 * Record filinq's outcome for the record that went out on the request.
	 *
	 * @param string               $schema     The record's schema (minutes, meeting or decision)
	 * @param string               $subjectId  The record's UUID
	 * @param array<string, mixed> $conclusion requestId, status, signedDocumentRef and signedAt
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return string signed, failed, or ignored when no record is out on the request.
	 */
	public function record(string $schema, string $subjectId, array $conclusion): string {
		$requestId = (string)($conclusion['requestId'] ?? '');
		$status    = (string)($conclusion['status'] ?? '');
		$data      = $this->recordOutOn(schema: $schema, subjectId: $subjectId, requestId: $requestId);
		if ($data === null || $this->isOutcome(status: $status) === false) {
			return 'ignored';
		}

		$patch = ['signingStatus' => 'failed'];
		if ($status === 'signed') {
			$patch = $this->signedPatch(data: $data, conclusion: $conclusion);
		}

		$this->objectService->saveObject(
			object: array_merge($data, $patch),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: 'decidiq', schema: $schema),
				uuid: $subjectId
			),
			_rbac: false,
			_multitenancy: false
		);

		if ($status === 'signed') {
			$this->signatures->resolveSignatureStage(minutesId: $subjectId, signingReference: $requestId);
			return 'signed';
		}

		$this->signatures->recordUnsignedOutcome(signingReference: $requestId, outcome: $status);
		return 'failed';
	}//end record()

	/**
	 * Whether filinq's status is an end: signed, declined, expired or cancelled.
	 *
	 * @param string $status The status filinq reported
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return bool
	 */
	private function isOutcome(string $status): bool {
		return $status === 'signed' || in_array($status, self::UNSIGNED, true) === true;
	}//end isOutcome()

	/**
	 * The record's data when it is out for signature on this request, else null.
	 *
	 * @param string $schema    The record's schema
	 * @param string $subjectId The record's UUID
	 * @param string $requestId filinq's signing request id
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<string, mixed>|null
	 */
	private function recordOutOn(string $schema, string $subjectId, string $requestId): ?array {
		if ($requestId === '' || in_array($schema, self::SCHEMAS, true) === false) {
			return null;
		}

		$data = $this->dataOf(
			entity: $this->objectService->find(
				id: $subjectId,
				options: new ObjectFindOptions(register: 'decidiq', schema: $schema),
				_rbac: false,
				_multitenancy: false
			)
		);
		if ((string)($data['signingRequestId'] ?? '') !== $requestId) {
			return null;
		}

		return $data;
	}//end recordOutOn()

	/**
	 * The data of a found object, [] when nothing was found.
	 *
	 * @param mixed $entity What the object service found
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(mixed $entity): array {
		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return [];
		}

		return (array)$entity->getObject();
	}//end dataOf()

	/**
	 * What a signed request writes on the record: the signed document, the
	 * signing time, and every signer stamped.
	 *
	 * @param array<string, mixed> $data       The record
	 * @param array<string, mixed> $conclusion signedDocumentRef and signedAt
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<string, mixed>
	 */
	private function signedPatch(array $data, array $conclusion): array {
		$signedAt = (string)($conclusion['signedAt'] ?? '');
		if ($signedAt === '') {
			$signedAt = gmdate('Y-m-d\TH:i:s\Z');
		}

		return [
			'signers' => $this->stampSigners(signers: (array)($data['signers'] ?? []), signedAt: $signedAt),
			'signingStatus' => 'signed',
			'signedCopy' => (string)($conclusion['signedDocumentRef'] ?? ''),
			'signedAt' => $signedAt,
		];
	}//end signedPatch()

	/**
	 * Every signer, with the signing time on each that has none yet.
	 *
	 * @param array<int, mixed> $signers  The record's signers
	 * @param string            $signedAt When the request was signed
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stampSigners(array $signers, string $signedAt): array {
		$stamped = [];
		foreach ($signers as $entry) {
			if (is_string($entry) === true) {
				$entry = ['participant' => $entry];
			}

			if (is_array($entry) === true) {
				$entry['signedAt'] = $this->signedAtOf(entry: $entry, signedAt: $signedAt);
				$stamped[]         = $entry;
			}
		}

		return $stamped;
	}//end stampSigners()

	/**
	 * A signer's own signing time, or the request's when it has none.
	 *
	 * @param array<string, mixed> $entry    The signer
	 * @param string               $signedAt When the request was signed
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return string
	 */
	private function signedAtOf(array $entry, string $signedAt): string {
		$own = (string)($entry['signedAt'] ?? '');
		if ($own === '') {
			return $signedAt;
		}

		return $own;
	}//end signedAtOf()
}//end class
