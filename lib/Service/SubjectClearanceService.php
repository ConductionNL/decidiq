<?php

/**
 * Subject Clearance Service
 *
 * One question, asked by every app that has a subject going through decidiq:
 * has everything that had to sign off signed off. The answer is yes or no, and
 * when it is no it names the route, the stage and who it is waiting on.
 *
 * WHY THE ANSWER NAMES WHO IT WAITS ON
 * -------------------------------------
 * "Not cleared" is not actionable. It makes somebody open decidiq, find the
 * route, find the live stage and read the actor off it, which is three screens
 * to learn one name. The name costs nothing to carry and is the only part of
 * the answer anybody acts on.
 *
 * WHY UNREACHABLE MEANS NOT CLEARED
 * ----------------------------------
 * A consumer that cannot reach decidiq treats the subject as NOT cleared. That
 * is stated in the requirement and it is the only safe direction: an app that
 * read a failed request as a clearance would let a case proceed past a sign-off
 * that may or may not exist, and would do it silently, and would do it for
 * every subject at once.
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
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Answers whether a subject's required routes have all concluded.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
 */
final class SubjectClearanceService {
	/**
	 * The stage statuses that mean a route is still going.
	 *
	 * @var array<int, string>
	 */
	private const UNFINISHED = ['active', 'pending'];

	/**
	 * Whether a subject is cleared, and what is holding it up if not.
	 *
	 * @param array<int, array<string, mixed>> $routes The routes on the subject, each with its stages.
	 *
	 * @return array{cleared: bool, waitingOn: array<int, array<string, mixed>>} The answer.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function clearanceFor(array $routes): array {
		$waiting = [];

		foreach ($routes as $route) {
			if (is_array($route) === false) {
				continue;
			}

			// Unset reads as required. A route somebody bothered to start is a
			// route somebody is waiting on, and the safe default for a clearance
			// question is the one that blocks.
			if (($route['required'] ?? true) !== true) {
				continue;
			}

			$stage = $this->liveStageOf(route: $route);
			if ($stage === null) {
				continue;
			}

			// A stage's own field names, with the step's as a fall-back: the
			// step number is `sequence`, the person asked is `assignedPerson`
			// and the name is `label`. Reading `order` and `actor` off a stored
			// stage answers step zero and nobody, which is an answer that names
			// nothing and still reads as a complete one.
			$waiting[] = [
				'route' => (string)($route['id'] ?? ($route['name'] ?? '')),
				'routeName' => (string)($route['name'] ?? ''),
				'stage' => (int)($stage['sequence'] ?? ($stage['order'] ?? 0)),
				'stageName' => (string)($stage['label'] ?? ($stage['name'] ?? '')),
				'actor' => (string)($stage['assignedPerson'] ?? ($stage['actor'] ?? '')),
				'dueAt' => (string)($stage['dueAt'] ?? ''),
			];
		}

		return ['cleared' => ($waiting === []), 'waitingOn' => $waiting];
	}//end clearanceFor()

	/**
	 * A one-line answer for a consumer that wants to show why.
	 *
	 * @param array{cleared: bool, waitingOn: array<int, array<string, mixed>>} $clearance The answer.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function describe(array $clearance): string {
		if (($clearance['cleared'] ?? false) === true) {
			return 'Every required sign-off has been given.';
		}

		$parts = [];
		foreach (($clearance['waitingOn'] ?? []) as $waiting) {
			$actor = (string)($waiting['actor'] ?? '');
			$route = (string)($waiting['routeName'] ?? ($waiting['route'] ?? 'a route'));

			if ($actor === '') {
				$parts[] = sprintf('%s, at a step with no actor assigned', $route);
				continue;
			}

			$parts[] = sprintf('%s, waiting on %s', $route, $actor);
		}

		return sprintf('Still waiting on %s.', implode('; ', $parts));
	}//end describe()

	/**
	 * The stage a route is currently on, or null when it has concluded.
	 *
	 * @param array<string, mixed> $route The route with its stages.
	 *
	 * @return array<string, mixed>|null The live stage.
	 */
	private function liveStageOf(array $route): ?array {
		$stages = ($route['stages'] ?? []);
		if (is_array($stages) === false || $stages === []) {
			// A required route with no stages at all has not concluded; it has
			// not started. Treating that as cleared would let a route that
			// failed to instantiate pass for one that finished.
			return ['sequence' => 0, 'assignedPerson' => '', 'label' => ''];
		}

		$active = null;
		$pending = null;
		foreach ($stages as $stage) {
			if (is_array($stage) === false) {
				continue;
			}

			$status = (string)($stage['status'] ?? '');
			if (in_array($status, self::UNFINISHED, true) === false) {
				continue;
			}

			if ($status === 'active' && $active === null) {
				$active = $stage;
				continue;
			}

			if ($pending === null) {
				$pending = $stage;
			}
		}

		return ($active ?? $pending);
	}//end liveStageOf()
}//end class
