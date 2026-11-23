<?php

/**
 * Decidiq approval nomination.
 *
 * A route step may let the person who sends the route name its approver,
 * within a bound the route declares: a Nextcloud group. This class takes the
 * nominations sent with the route, refuses one outside its step's group (and
 * names the group), refuses a nomination step left without a nominee, and
 * writes each accepted nominee onto its step as the step's person, so the
 * stage the engine creates is assigned to them. A step that declares no
 * bound ignores any nomination sent to it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IGroupManager;
use RuntimeException;

/**
 * Applies the approvers a requester named to the steps that let them.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
 */
final class ApprovalNomination {
	/**
	 * The step field that declares the bound.
	 *
	 * @var string
	 */
	public const BOUND_FIELD = 'nominationGroup';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groups Answers whether the nominee is in the
	 *        bound; without it every nomination step is refused.
	 */
	public function __construct(
		private readonly ?IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Whether a step lets the requester name its approver.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return bool True when the step declares a bound.
	 */
	private static function takesNomination(array $step): bool {
		return self::boundOf(step: $step) !== '';
	}//end takesNomination()

	/**
	 * The route with every nominated approver written onto their step.
	 *
	 * Every step is checked before the route is returned, so a refused
	 * nomination leaves nothing half-applied and nothing is written.
	 *
	 * @param array<string, mixed> $route       The route as the caller sent it.
	 * @param array<mixed, mixed>  $nominations The nominees, keyed by the step's order.
	 * @param string               $requester   Who sends the route and names the approvers.
	 *
	 * @return array<string, mixed> The route, its nomination steps assigned.
	 *
	 * @throws RuntimeException When a nomination step has no nominee, or the
	 *         nominee is outside its group.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
	 */
	public function applyTo(array $route, array $nominations, string $requester): array {
		$steps = self::stepsOf(route: $route);
		if ($steps === null) {
			return $route;
		}

		$touched = false;
		foreach ($steps as $index => $step) {
			if (is_array($step) === false || self::takesNomination(step: $step) === false) {
				// No bound: a nomination sent to this step is ignored.
				continue;
			}

			$steps[$index] = $this->nominated(
				step: $step,
				nominee: self::nomineeFor(step: $step, index: $index, nominations: $nominations),
				requester: $requester,
			);
			$touched       = true;
		}

		if ($touched === false) {
			return $route;
		}

		$route['steps'] = $steps;
		return $route;
	}//end applyTo()

	/**
	 * The step, assigned to its nominee once the nominee is inside its group.
	 *
	 * The step's own actor and any actor rule give way to the nominee: a rule
	 * left on the step would be resolved when the stage goes live and would
	 * replace the person the requester named.
	 *
	 * @param array<string, mixed> $step      The step.
	 * @param string               $nominee   The user the requester named.
	 * @param string               $requester Who named them.
	 *
	 * @return array<string, mixed> The step, assigned.
	 *
	 * @throws RuntimeException When no one is named, or the nominee is outside the group.
	 */
	private function nominated(array $step, string $nominee, string $requester): array {
		$bound = self::boundOf(step: $step);
		$label = self::labelOf(step: $step);

		if ($nominee === '') {
			throw new RuntimeException(
				sprintf('%s asks you to name its approver from the group %s, and nobody was named.', $label, $bound)
			);
		}

		if ($this->groups === null) {
			throw new RuntimeException(
				sprintf('%s lets you name its approver, and the nomination cannot be checked here.', $label)
			);
		}

		if ($this->groups->isInGroup($nominee, $bound) === false) {
			throw new RuntimeException(
				sprintf('%s takes an approver from the group %s, and %s is not in it.', $label, $bound, $nominee)
			);
		}

		unset($step['actorRule'], $step['actorRuleSubject']);
		$step['actorType']       = 'person';
		$step['actor']           = $nominee;
		$step['nominationGroup'] = $bound;
		$step['nominatedBy']     = $requester;

		return $step;
	}//end nominated()

	/**
	 * The nominee sent for a step, by the step's order.
	 *
	 * @param array<string, mixed> $step        The step.
	 * @param int|string           $index       The step's position, the fallback order.
	 * @param array<mixed, mixed>  $nominations The nominees, keyed by order.
	 *
	 * @return string The nominee's user id, or empty.
	 */
	private static function nomineeFor(array $step, int|string $index, array $nominations): string {
		$order = (int)($step['order'] ?? 0);
		if ($order <= 0) {
			$order = ((int)$index + 1);
		}

		$nominee = ($nominations[$order] ?? ($nominations[(string)$order] ?? ''));
		if (is_string($nominee) === false) {
			return '';
		}

		return trim($nominee);
	}//end nomineeFor()

	/**
	 * The group a step bounds its nominee to, or empty.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string The group id.
	 */
	private static function boundOf(array $step): string {
		$bound = ($step[self::BOUND_FIELD] ?? '');
		if (is_string($bound) === false) {
			return '';
		}

		return trim($bound);
	}//end boundOf()

	/**
	 * How a refusal names the step.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string The step's label, or its number.
	 */
	private static function labelOf(array $step): string {
		$label = trim((string)($step['label'] ?? ''));
		if ($label !== '') {
			return sprintf('The step "%s"', $label);
		}

		return sprintf('Step %d', (int)($step['order'] ?? 0));
	}//end labelOf()

	/**
	 * The route's steps as a list, decoding the stored JSON form.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return array<int|string, mixed>|null The steps, or null when there are none to read.
	 */
	private static function stepsOf(array $route): ?array {
		$steps = ($route['steps'] ?? null);
		if (is_string($steps) === true) {
			$steps = json_decode($steps, true);
		}

		if (is_array($steps) === false || $steps === []) {
			return null;
		}

		return $steps;
	}//end stepsOf()
}//end class
