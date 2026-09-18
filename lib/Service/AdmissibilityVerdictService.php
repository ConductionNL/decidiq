<?php

/**
 * Admissibility Verdict Service
 *
 * The first question a request meets is not whether to grant it but whether it
 * can be considered at all. An intake step yields that verdict:
 * `ontvankelijk` and the route walks on, `niet-ontvankelijk` and the route ends
 * where it stands.
 *
 * WHY A GROUND IS REQUIRED AND NOT MERELY EXPECTED
 * ------------------------------------------------
 * A `niet-ontvankelijk` verdict is a refusal to consider somebody's request. It
 * is contestable, and a refusal that names no ground cannot be contested,
 * because the party has nothing to argue against. So it is refused at the point
 * of recording rather than left to a reviewer to notice later, when the term to
 * object has already started running.
 *
 * WHY AN INADMISSIBLE REQUEST DOES NOT MERELY SKIP AHEAD
 * ------------------------------------------------------
 * The route closes with `ended-at-intake` and no later step is instantiated. A
 * route that ran on would put an approval on somebody's task list for a request
 * that was never admissible, and somebody would eventually grant it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Records an admissibility verdict and says what the route does next.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */
final class AdmissibilityVerdictService {
	/**
	 * The request can be considered.
	 *
	 * @var string
	 */
	public const ONTVANKELIJK = 'ontvankelijk';

	/**
	 * It cannot.
	 *
	 * @var string
	 */
	public const NIET_ONTVANKELIJK = 'niet-ontvankelijk';

	/**
	 * The verdicts an intake step may yield.
	 *
	 * @var array<int, string>
	 */
	public const VERDICTS = [self::ONTVANKELIJK, self::NIET_ONTVANKELIJK];

	/**
	 * The outcome a route closes with when intake refuses it.
	 *
	 * @var string
	 */
	public const OUTCOME_ENDED_AT_INTAKE = 'ended-at-intake';

	/**
	 * The step kind that yields a verdict rather than an approval.
	 *
	 * @var string
	 */
	public const STEP_KIND_INTAKE = 'intake';

	/**
	 * Record a verdict on an intake step.
	 *
	 * @param array<string, mixed> $step The step the verdict is recorded on.
	 * @param string $verdict One of the two verdicts.
	 * @param string $ground The ground it rests on.
	 * @param string $decidedBy Who gave it.
	 * @param string $decidedAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array{verdict: string, ground: string, decidedBy: string, decidedAt: string, routeOutcome: ?string, advances: bool} The verdict and what the route does.
	 *
	 * @throws InvalidArgumentException When the step is not an intake step, the verdict is unknown, or a refusal names no ground.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function record(
		array $step,
		string $verdict,
		string $ground = '',
		string $decidedBy = '',
		string $decidedAt = '',
	): array {
		if ((string)($step['stepKind'] ?? 'approval') !== self::STEP_KIND_INTAKE) {
			throw new InvalidArgumentException(
				'An admissibility verdict belongs on an intake step; this step is an approval step and expects a grant or a refusal.'
			);
		}

		if (in_array($verdict, self::VERDICTS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown verdict "%s"; expected %s.', $verdict, implode(' or ', self::VERDICTS))
			);
		}

		if (trim($decidedBy) === '') {
			throw new InvalidArgumentException('A verdict has to name who gave it.');
		}

		if ($verdict === self::NIET_ONTVANKELIJK && trim($ground) === '') {
			// Refused here rather than at review: by then the term to object has
			// already started running against a refusal nobody can argue with.
			throw new InvalidArgumentException(
				'A niet-ontvankelijk verdict needs the ground it rests on; without one the party has nothing to contest.'
			);
		}

		$inadmissible = ($verdict === self::NIET_ONTVANKELIJK);

		return [
			'verdict' => $verdict,
			'ground' => trim($ground),
			'decidedBy' => $decidedBy,
			'decidedAt' => ($decidedAt === '' ? (new DateTimeImmutable())->format(DateTimeImmutable::ATOM) : $decidedAt),
			'routeOutcome' => ($inadmissible === true ? self::OUTCOME_ENDED_AT_INTAKE : null),
			'advances' => ($inadmissible === false),
		];
	}//end record()

	/**
	 * The verdict as the consuming case app reads it off the decision.
	 *
	 * A projection rather than the whole record, because the case app shows the
	 * verdict and its ground and has no business with the rest of the route.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return array<string, mixed>|null The verdict, or null when the route had no intake step.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function readFrom(array $decision): ?array {
		$verdict = (string)($decision['ontvankelijkheid'] ?? '');
		if (in_array($verdict, self::VERDICTS, true) === false) {
			return null;
		}

		return [
			'ontvankelijkheid' => $verdict,
			'ground' => (string)($decision['ontvankelijkheidGround'] ?? ''),
			'decidedBy' => (string)($decision['ontvankelijkheidDecidedBy'] ?? ''),
		];
	}//end readFrom()
}//end class
