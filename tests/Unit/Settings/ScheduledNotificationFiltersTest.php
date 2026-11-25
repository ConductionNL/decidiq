<?php

/**
 * Every scheduled reminder is written in OpenRegister's filter grammar.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * A scheduled rule's filter clause is either a scalar (equality) or an
 * operator object; a bare list of values is not in OpenRegister's grammar and
 * matches nothing, so the rule never fires. Reminders before or after a date
 * carry that date as a clause.
 *
 * @spec exclude register-configuration guard over every declared scheduled notification
 */
final class ScheduledNotificationFiltersTest extends TestCase {

	/**
	 * Schemas kept only to hold rows migrated to their plain-words successors; their rules have no live objects to fire on.
	 *
	 * @var list<string>
	 */
	private const RETIRED = ['toezegging', 'nevenfunctie', 'onboarding-traject', 'offboarding-traject', 'cyclus-stap', 'termijnagenda-item'];

	/**
	 * Rules that stay silent on purpose, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const SILENT = [
		'planned-agenda-item.termijnagendaRappelPeriodeAangebroken' => 'the planned period is text (a month or a quarter), not a date a clause '
			.'can compare; writing only the states would remind about every planned item weekly',
	];

	/**
	 * Every clause of every scheduled filter on a live schema is in the grammar.
	 *
	 * @return void
	 */
	public function testEveryScheduledFilterIsInTheGrammar(): void {
		$checked = 0;
		foreach ($this->scheduledRules() as $ref => $filter) {
			if (isset(self::SILENT[$ref]) === true) {
				continue;
			}

			foreach ($this->clauses(filter: $filter) as $field => $clause) {
				self::assertFalse(
					condition: is_array($clause) === true && array_is_list($clause) === true,
					message: $ref.': the clause on '.$field.' is a bare list; write {"operator": "in", "values": [...]}'
				);
			}

			$checked++;
		}

		self::assertGreaterThan(expected: 20, actual: $checked);
	}//end testEveryScheduledFilterIsInTheGrammar()

	/**
	 * A reminder before or after a date names that date in its filter.
	 *
	 * @return void
	 */
	public function testDatedRemindersCarryTheirDate(): void {
		$rules = $this->scheduledRules();
		$dated = [
			'governance-commitment.toezeggingRappelBeforeDeadline' => 'deadline',
			'governance-commitment.toezeggingRappelOverdue' => 'deadline',
			'consultation-request.consultationRappelBeforeResponseDate' => 'requestedResponseDate',
			'consultation-request.consultationRappelOverdue' => 'requestedResponseDate',
			'consultation-request.consultationOpschortingExpiry' => 'suspensionTo',
			'zienswijze.zienswijzeRappelBeforeDeadline' => 'deadline',
			'zienswijze.zienswijzeRappelOverdue' => 'deadline',
			'advice-request.adviesaanvraagRappelBeforeDeadline' => 'requestedByDate',
			'advice-request.adviesaanvraagRappelOverdue' => 'requestedByDate',
			'regeling-versie.inwerkingtredingApproaching14' => 'effectiveDate',
			'regeling-versie.inwerkingtredingApproaching3' => 'effectiveDate',
			'planning-cycle-step.behandelingApproachingUnscheduled' => 'handlingDate',
			'planning-cycle-step.aanleveringLate' => 'deliveryDeadline',
		];

		foreach ($dated as $ref => $field) {
			self::assertArrayHasKey(key: $ref, array: $rules);
			$operator = ($rules[$ref][$field]['operator'] ?? null);
			self::assertContains(needle: $operator, haystack: ['withinNext', 'before'], message: $ref.' compares '.$field);
		}
	}//end testDatedRemindersCarryTheirDate()

	/**
	 * The scheduled filters of the shipped register, keyed `slug.rule`, live schemas only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scheduledRules(): array {
		$rules = [];
		foreach ((SettingsService::shippedRegisterDescriptor()['components']['schemas'] ?? []) as $name => $schema) {
			$slug = (string)($schema['slug'] ?? $name);
			if (in_array($slug, self::RETIRED, true) === true) {
				continue;
			}

			foreach (($schema['x-openregister-notifications'] ?? []) as $rule => $declared) {
				$trigger = (array)($declared['trigger'] ?? []);
				if (($trigger['type'] ?? null) === 'scheduled' && is_array($trigger['filter'] ?? null) === true) {
					$rules[$slug.'.'.$rule] = $trigger['filter'];
				}
			}
		}

		return $rules;
	}//end scheduledRules()

	/**
	 * The field clauses of a filter, nested `all` and `any` included.
	 *
	 * @param array<string, mixed> $filter The filter.
	 *
	 * @return array<string, mixed>
	 */
	private function clauses(array $filter): array {
		$clauses = [];
		foreach ($filter as $key => $value) {
			if (in_array($key, ['all', 'any'], true) === false) {
				$clauses[$key] = $value;
				continue;
			}

			foreach ((array)$value as $nested) {
				$clauses += $this->clauses(filter: (array)$nested);
			}
		}

		return $clauses;
	}//end clauses()
}//end class
