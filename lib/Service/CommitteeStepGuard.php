<?php

/**
 * Decidiq committee step guard.
 *
 * A route step of kind `committee` is decided by a governance body in a
 * meeting, not by one person signing off. This guard refuses any single
 * actor's completing act on a running committee stage, and the refusal names
 * the body that has to decide, so the person who tried knows where the
 * decision has to come from.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Refuses a single actor closing a step a governance body has to decide.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
final class CommitteeStepGuard {
	/**
	 * The step kind a governance body decides.
	 *
	 * @var string
	 */
	public const STEP_KIND_COMMITTEE = 'committee';

	/**
	 * The schema slug of a governance body.
	 *
	 * @var string
	 */
	private const BODY_SCHEMA = 'governance-body';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads the body's name for the refusal.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Whether a stage is a committee step.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool True for a committee stage.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function isCommitteeStage(array $stage): bool {
		return (string)($stage['stepKind'] ?? '') === self::STEP_KIND_COMMITTEE;
	}//end isCommitteeStage()

	/**
	 * Refuse a single actor's completing act on a committee stage.
	 *
	 * Any other stage passes untouched. Nothing is written before this runs,
	 * so a refused act leaves no action row and no stage change.
	 *
	 * @param array<string, mixed> $stage The stage the act addresses.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On a committee stage, naming the body that decides.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function assertNotClosedByOneActor(array $stage): void {
		if ($this->isCommitteeStage(stage: $stage) === false) {
			return;
		}

		$body = $this->bodyName(stage: $stage);
		if ($body === '') {
			throw new RuntimeException(
				'This is a committee step, which a governance body decides in a meeting, but it names no body. One person cannot close it.'
			);
		}

		throw new RuntimeException(
			sprintf('This is a committee step: %s decides it in a meeting. One person cannot close it.', $body)
		);
	}//end assertNotClosedByOneActor()

	/**
	 * The name of the body a committee stage refers to.
	 *
	 * Falls back to the body's id when the body cannot be read, so the refusal
	 * still points somewhere; empty only when the stage names no body at all.
	 *
	 * @param array<string, mixed> $stage The committee stage.
	 *
	 * @return string The body's name, its id, or empty.
	 */
	private function bodyName(array $stage): string {
		$bodyId = $this->relationId(value: ($stage['committeeBody'] ?? null));
		if ($bodyId === '') {
			return '';
		}

		$body = $this->store->find(schema: self::BODY_SCHEMA, uuid: $bodyId);
		$name = trim((string)($body['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return $bodyId;
	}//end bodyName()

	/**
	 * The id a relation value carries, whether stored as a uuid or as an object.
	 *
	 * @param mixed $value The stored relation.
	 *
	 * @return string The id, or empty.
	 */
	private function relationId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? '');
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end relationId()
}//end class
