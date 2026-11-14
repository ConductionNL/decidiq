<?php

/**
 * Decidiq Decision Competence Guard
 *
 * The one place that decides whether a decision may be recorded by the body
 * taking it (configurable-types-domain-model D5). A decision type names its
 * competent body; a decision of that type is only recorded on a meeting of
 * that body. A type that names none falls back to the meeting's body, and a
 * decision with neither is refused: absence of a declared competence is never
 * universal competence.
 *
 * The guard throws. It never returns a value a caller could read as "check
 * skipped", and a dependency that fails refuses rather than permits.
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
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\DecisionCompetenceRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Asserts that the body taking a decision is competent to take it.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */
class DecisionCompetenceGuard {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService       Reads the decision type.
	 * @param ParticipantResolver    $participantResolver Resolves a meeting's body.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
	) {
	}//end __construct()

	/**
	 * Refuse a decision its body is not competent to take.
	 *
	 * A decision without a type is not governed by a decision type and is left
	 * to the guards that already apply to it.
	 *
	 * @param array<string, mixed> $decision The decision as it is about to be saved.
	 *
	 * @return void
	 *
	 * @throws DecisionCompetenceRefusedException When the body is not competent, no competent body
	 *                                            can be established, or competence cannot be checked.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
	 */
	public function assertCompetent(array $decision): void {
		$typeId = self::reference(value: ($decision['type'] ?? null));
		if ($typeId === '') {
			return;
		}

		$competentBody = $this->competentBodyOf(typeId: $typeId);
		$meetingBody   = $this->meetingBodyOf(decision: $decision);

		if ($competentBody === '') {
			if ($meetingBody === '') {
				throw new DecisionCompetenceRefusedException(
					message: 'The decision was not saved: its type names no competent body and it belongs to no meeting of a body.'
				);
			}

			return;
		}

		if ($meetingBody !== $competentBody) {
			throw new DecisionCompetenceRefusedException(
				message: 'The decision was not saved: only the body its type names as competent may take a decision of this type.'
			);
		}
	}//end assertCompetent()

	/**
	 * The competent body a decision type names, or '' when it names none.
	 *
	 * @param string $typeId The decision type.
	 *
	 * @return string The body uuid, or ''.
	 *
	 * @throws DecisionCompetenceRefusedException When the type does not exist or cannot be read.
	 */
	private function competentBodyOf(string $typeId): string {
		try {
			$type = $this->objectService->find(id: $typeId, register: 'decidiq', schema: 'decision-template', _rbac: false);
		} catch (DoesNotExistException) {
			$type = null;
		} catch (Throwable $e) {
			throw new DecisionCompetenceRefusedException(
				message: 'The decision was not saved: its decision type could not be read to check who may take it.',
				code: 0,
				previous: $e
			);
		}

		if ($type === null) {
			throw new DecisionCompetenceRefusedException(message: 'The decision was not saved: its decision type does not exist.');
		}

		return self::reference(value: ($type->jsonSerialize()['competentBody'] ?? null));
	}//end competentBodyOf()

	/**
	 * The body of the meeting a decision belongs to, or '' when there is none.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return string The body uuid, or ''.
	 *
	 * @throws DecisionCompetenceRefusedException When the meeting cannot be read.
	 */
	private function meetingBodyOf(array $decision): string {
		$meetingId = self::reference(value: ($decision['meeting'] ?? null));
		if ($meetingId === '') {
			return '';
		}

		try {
			return (string)$this->participantResolver->resolveGovernanceBodyId(meetingId: $meetingId);
		} catch (Throwable $e) {
			throw new DecisionCompetenceRefusedException(
				message: "The decision was not saved: its meeting's body could not be read to check who may take it.",
				code: 0,
				previous: $e
			);
		}
	}//end meetingBodyOf()

	/**
	 * A reference as a uuid string: a plain id, or an object carrying one.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The id, or '' when there is none.
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()
}//end class
