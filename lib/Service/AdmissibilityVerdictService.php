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
 *
 * NOT REACHABLE YET, AND THAT IS THE FIRST THING TO KNOW ABOUT THIS CLASS
 * ------------------------------------------------------------------------
 * Measured 2026-09-18 with `git grep -l`: this class is named by exactly two
 * files, its own and its own unit test. Nothing in lib/ constructs it, no DI
 * registration mentions it, no route reaches it. Everything below describes what
 * it WOULD do; none of it runs today, and the green suite beside it tests the
 * class in isolation, so it cannot tell you otherwise.
 *
 * Read this before believing a present-tense sentence further down. Scope for
 * making it reachable is in
 * openspec/changes/the-decision-as-a-walked-process/reachability-scope.md.
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
	 * @param array<string, mixed> $step    The step the verdict is recorded on.
	 * @param IntakeVerdict        $verdict The verdict, its ground, who gave it and when.
	 *
	 * @return array{verdict: string, ground: string, decidedBy: string,
	 *         decidedAt: string, routeOutcome: ?string, advances: bool} The
	 *         verdict and what the route does.
	 *
	 * @throws InvalidArgumentException When the step is not an intake step, the verdict is unknown, or a refusal names no ground.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function record(array $step, IntakeVerdict $verdict): array {
		$this->assertIntakeStep(step: $step);
		$this->assertKnownVerdict(verdict: $verdict->verdict);
		$this->assertAttributable(verdict: $verdict);

		$inadmissible = ($verdict->verdict === self::NIET_ONTVANKELIJK);

		return [
			'verdict' => $verdict->verdict,
			'ground' => trim($verdict->ground),
			'decidedBy' => $verdict->decidedBy,
			'decidedAt' => $this->decidedAtOrNow(decidedAt: $verdict->decidedAt),
			'routeOutcome' => $this->routeOutcomeFor(inadmissible: $inadmissible),
			'advances' => ($inadmissible === false),
		];
	}//end record()

	/**
	 * Refuse a verdict recorded on anything but an intake step.
	 *
	 * @param array<string, mixed> $step The step the verdict is recorded on.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the step is not an intake step.
	 */
	private function assertIntakeStep(array $step): void {
		if ((string)($step['stepKind'] ?? 'approval') !== self::STEP_KIND_INTAKE) {
			throw new InvalidArgumentException(
				'An admissibility verdict belongs on an intake step; this step is an approval step and expects a grant or a refusal.'
			);
		}
	}//end assertIntakeStep()

	/**
	 * Refuse a verdict that is neither of the two known ones.
	 *
	 * @param string $verdict The verdict as given.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the verdict is unknown.
	 */
	private function assertKnownVerdict(string $verdict): void {
		if (in_array($verdict, self::VERDICTS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown verdict "%s"; expected %s.', $verdict, implode(' or ', self::VERDICTS))
			);
		}
	}//end assertKnownVerdict()

	/**
	 * Refuse a verdict that names nobody, or a refusal that names no ground.
	 *
	 * @param IntakeVerdict $verdict The verdict as given.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When nobody gave it, or a refusal has no ground.
	 */
	private function assertAttributable(IntakeVerdict $verdict): void {
		if (trim($verdict->decidedBy) === '') {
			throw new InvalidArgumentException('A verdict has to name who gave it.');
		}

		if ($verdict->verdict === self::NIET_ONTVANKELIJK && trim($verdict->ground) === '') {
			// Refused here rather than at review: by then the term to object has
			// already started running against a refusal nobody can argue with.
			throw new InvalidArgumentException(
				'A niet-ontvankelijk verdict needs the ground it rests on; without one the party has nothing to contest.'
			);
		}
	}//end assertAttributable()

	/**
	 * The instant the verdict was given, now when none was given.
	 *
	 * @param string $decidedAt The given instant, or empty.
	 *
	 * @return string An ISO-8601 instant.
	 */
	private function decidedAtOrNow(string $decidedAt): string {
		if ($decidedAt === '') {
			return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		}

		return $decidedAt;
	}//end decidedAtOrNow()

	/**
	 * The outcome the route closes with: ended at intake when inadmissible.
	 *
	 * @param bool $inadmissible Whether the verdict refuses the request.
	 *
	 * @return string|null The outcome, or null when the route walks on.
	 */
	private function routeOutcomeFor(bool $inadmissible): ?string {
		if ($inadmissible === true) {
			return self::OUTCOME_ENDED_AT_INTAKE;
		}

		return null;
	}//end routeOutcomeFor()

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
