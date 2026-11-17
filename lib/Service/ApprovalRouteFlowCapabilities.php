<?php

/**
 * What the installed OpenRegister can run for an approval route.
 *
 * A route compiled to a flow may only use what OpenRegister's task and flow
 * catalogue offers. This class answers "is feature X there", read from the
 * catalogue OpenRegister ships (its task vocabularies and flow routing class),
 * never from a version number: a backport or a fork answers by what it has.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Answers which route features the installed OpenRegister offers.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */
class ApprovalRouteFlowCapabilities {
	/**
	 * A step that sends the proposal back to an earlier step.
	 */
	public const RETURN_EDGE = 'return to an earlier step';

	/**
	 * One deadline for the whole route, split over its steps.
	 */
	public const DEADLINE_SPLIT = 'route deadline split over its steps';

	/**
	 * An outcome applied when a step stays silent past its deadline.
	 */
	public const LAPSE_OUTCOME = 'declared outcome on silence';

	/**
	 * A step that is held, not concluded, when it stays silent.
	 */
	public const HOLD = 'hold on silence';

	/**
	 * A reminder, or the substitute asked, before the deadline.
	 */
	public const REMINDER = 'reminder before the deadline';

	/**
	 * A performer found by a rule, such as the manager of the owning organisation.
	 */
	public const PERFORMER_RULE = 'performer by rule';

	/**
	 * People a route says may never act on it.
	 */
	public const EXCLUSION_LIST = 'exclusion list';

	/**
	 * Parallel steps that conclude when some, not all, of them approved.
	 */
	public const PARTIAL_THRESHOLD = 'threshold of some of the parallel steps';

	/**
	 * Every feature, in the order a refusal lists them.
	 *
	 * @var list<string>
	 */
	public const ALL = [
		self::RETURN_EDGE,
		self::DEADLINE_SPLIT,
		self::LAPSE_OUTCOME,
		self::HOLD,
		self::REMINDER,
		self::PERFORMER_RULE,
		self::EXCLUSION_LIST,
		self::PARTIAL_THRESHOLD,
	];

	/**
	 * OpenRegister's task entity, where the task vocabularies live.
	 */
	private const TASK_CLASS = 'OCA\\OpenRegister\\Db\\Task';

	/**
	 * OpenRegister's exit router: present means a node can route by outcome.
	 */
	private const ROUTER_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\FlowTokenRouter';

	/**
	 * Constructor.
	 *
	 * @param list<string>|null $offered The features to report as offered; null reads them from OpenRegister.
	 */
	public function __construct(
		private readonly ?array $offered = null,
	) {
	}//end __construct()

	/**
	 * Whether the installed OpenRegister offers a feature.
	 *
	 * @param string $feature One of the constants above.
	 *
	 * @return bool True when a compiled route may use it.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function offers(string $feature): bool {
		return in_array($feature, $this->offered(), true);
	}//end offers()

	/**
	 * The offered features.
	 *
	 * @return list<string> The features.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function offered(): array {
		if ($this->offered !== null) {
			return $this->offered;
		}

		return $this->readFromOpenRegister();
	}//end offered()

	/**
	 * Read the offered features from OpenRegister's catalogue.
	 *
	 * Only what the catalogue proves is reported. A feature with no catalogue
	 * entry (deadline split, reminder, exclusion list, partial threshold) is
	 * absent until OpenRegister ships one, which is the fail-closed answer: a
	 * route that needs it is refused rather than run without it.
	 *
	 * @return list<string> The features.
	 */
	private function readFromOpenRegister(): array {
		$offered = [];
		if (class_exists(self::ROUTER_CLASS) === true) {
			$offered[] = self::RETURN_EDGE;
		}

		$behaviours = $this->taskVocabulary(constant: 'OUTCOME_BEHAVIOURS');
		if (in_array('hold', $behaviours, true) === true) {
			$offered[] = self::HOLD;
		}

		if (in_array('outcome', $behaviours, true) === true) {
			$offered[] = self::LAPSE_OUTCOME;
		}

		if (in_array('rule', $this->taskVocabulary(constant: 'ROUTING_STRATEGIES'), true) === true) {
			$offered[] = self::PERFORMER_RULE;
		}

		return $offered;
	}//end readFromOpenRegister()

	/**
	 * One of the task entity's vocabularies, or none when it is not there.
	 *
	 * @param string $constant The constant name.
	 *
	 * @return list<string> The vocabulary.
	 */
	private function taskVocabulary(string $constant): array {
		$name = self::TASK_CLASS . '::' . $constant;
		if (class_exists(self::TASK_CLASS) === false || defined($name) === false) {
			return [];
		}

		$value = constant($name);
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_map('strval', $value));
	}//end taskVocabulary()
}//end class
