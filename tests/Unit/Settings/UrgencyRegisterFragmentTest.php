<?php

/**
 * The urgency fragment declares the urgency fields, the awaitingRatification
 * calculation and the notification rules, and leaves the lifecycle alone.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/decision-management/spec.md#requirement-decision-urgency-fields
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Task 1 of urgent-decision-procedure, read from the shipped fragment.
 */
class UrgencyRegisterFragmentTest extends TestCase {

	/**
	 * The fragment's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/46-urgency-policy.json'), true);
		return $fragment['components']['schemas'];
	}

	public function testDecisionCarriesTheUrgencyFieldsAndLeavesTheLifecycleAlone(): void {
		$decision = self::schemas()['Decision'];
		foreach (['isUrgent', 'urgencyReason', 'urgencyDeclaredBy', 'urgencyDeclaredAt', 'awaitingRatification'] as $field) {
			self::assertArrayHasKey($field, $decision['properties'], $field);
		}

		self::assertArrayNotHasKey('required', $decision, 'no field becomes required');
		self::assertArrayNotHasKey('x-openregister-lifecycle', $decision);
		self::assertTrue($decision['properties']['awaitingRatification']['readOnly']);
	}

	public function testAwaitingRatificationIsUrgentWithAnOpenRatifyingStage(): void {
		$decision    = self::schemas()['Decision'];
		$calculation = $decision['x-openregister-calculations']['awaitingRatification'];
		self::assertTrue($calculation['materialise']);
		self::assertSame(
			['and' => [
				['eq' => [['prop' => 'isUrgent'], true]],
				['gt' => [['prop' => '@aggregate.ratifyingStageCount'], ['+' => [['prop' => '@aggregate.decidedRatifyingStageCount'], ['prop' => '@aggregate.skippedRatifyingStageCount']]]]],
			]],
			$calculation['expression']
		);
		foreach (['ratifyingStageCount' => null, 'decidedRatifyingStageCount' => 'decided', 'skippedRatifyingStageCount' => 'skipped'] as $name => $status) {
			$ref = $decision['x-openregister-aggregate-refs'][$name];
			self::assertSame('decision-stage', $ref['schema']);
			self::assertSame('ratifying', $ref['filters']['stageType']);
			self::assertSame('@self.id', $ref['filters']['decision']);
			self::assertSame($status, ($ref['filters']['status'] ?? null), $name);
		}
	}

	public function testMeetingCarriesTheOptionalDeviationFields(): void {
		$meeting = self::schemas()['Meeting'];
		self::assertSame(['shortenedNotice', 'actualNoticeHours', 'noticeDeviationReason'], array_keys($meeting['properties']));
		self::assertArrayNotHasKey('required', $meeting);
	}

	public function testTheNotificationRulesUseTheVerifiedDialect(): void {
		$rules = self::schemas()['Decision']['x-openregister-notifications'];
		self::assertSame(['urgentDecisionDeclared', 'urgentRatificationDue'], array_keys($rules));
		self::assertSame('updated', $rules['urgentDecisionDeclared']['trigger']['type']);
		self::assertSame('isUrgent', $rules['urgentDecisionDeclared']['trigger']['condition']['field']);
		self::assertSame('scheduled', $rules['urgentRatificationDue']['trigger']['type']);
		foreach ($rules as $name => $rule) {
			self::assertSame(['nl', 'en'], array_keys($rule['subject']), $name);
			foreach ($rule['recipients'] as $recipient) {
				self::assertNotSame('field', ($recipient['kind'] ?? null), $name . ' has no kind:field recipient');
			}
		}
	}
}
