<?php

/**
 * Tests for UrgencyPolicyValidator.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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
 * @spec openspec/changes/urgent-decision-procedure/specs/process-configuration/spec.md#requirement-per-template-urgency-policy
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\UrgencyPolicyValidator;
use PHPUnit\Framework\TestCase;

/**
 * An urgency policy that cannot be honoured is refused, naming the fault.
 *
 * @covers \OCA\Decidiq\Service\UrgencyPolicyValidator
 */
class UrgencyPolicyValidatorTest extends TestCase {

	/**
	 * The municipal council policy of the spec, with entries replaced.
	 *
	 * @param array<string, mixed> $change Entries to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function policy(array $change=[]): array {
		return array_merge(
			[
				'allowedTriggerRoles' => ['chair'],
				'minimumNoticeFloorHours' => 24,
				'responseDeadlineHours' => ['min' => 12, 'max' => 96],
				'ratificationRequired' => true,
				'ratifyingBody' => 'gemeenteraad',
			],
			$change
		);
	}

	/**
	 * The faults of a policy, with `gemeenteraad` the only body that exists.
	 *
	 * @param mixed $policy The policy.
	 *
	 * @return string[]
	 */
	private static function faults(mixed $policy): array {
		return (new UrgencyPolicyValidator())->errors(policy: $policy, bodyExists: static fn (string $ref): bool => $ref === 'gemeenteraad');
	}

	public function testTheMunicipalCouncilPolicySaves(): void {
		self::assertSame([], self::faults(self::policy()));
		self::assertSame([], self::faults(self::policy(['allowedTriggerRoles' => ['chair', 'secretary', 'board-member'], 'ratificationRequired' => false, 'ratifyingBody' => null])));
		self::assertSame([], self::faults(['allowedTriggerRoles' => ['chair'], 'ratifyingBody' => 'gemeenteraad']), 'floor and bounds are optional');
	}

	public function testInvertedBoundsAreNamed(): void {
		self::assertSame(
			['urgencyPolicy.responseDeadlineHours is inverted: min 96 is above max 12.'],
			self::faults(self::policy(['responseDeadlineHours' => ['min' => 96, 'max' => 12]]))
		);
		self::assertSame([], self::faults(self::policy(['responseDeadlineHours' => ['min' => 24, 'max' => 24]])), 'equal bounds');
		self::assertSame(
			['urgencyPolicy.responseDeadlineHours must be {min, max} in whole hours, 0 or more.'],
			self::faults(self::policy(['responseDeadlineHours' => ['min' => '4', 'max' => 72]]))
		);
	}

	public function testRatificationNeedsABodyThatResolves(): void {
		self::assertSame(
			['urgencyPolicy.ratifyingBody is required when ratificationRequired is true.'],
			self::faults(self::policy(['ratifyingBody' => null]))
		);
		$noRequired = self::policy();
		unset($noRequired['ratificationRequired'], $noRequired['ratifyingBody']);
		self::assertSame(['urgencyPolicy.ratifyingBody is required when ratificationRequired is true.'], self::faults($noRequired), 'required by default');
		self::assertSame(['urgencyPolicy.ratifyingBody "rvc" is not a governance body.'], self::faults(self::policy(['ratifyingBody' => 'rvc'])));
		self::assertSame(['urgencyPolicy.ratificationRequired must be true or false.'], self::faults(self::policy(['ratificationRequired' => 'yes'])));
	}

	public function testTriggerRolesMustBeNonEmptyAndRecognised(): void {
		self::assertSame(['urgencyPolicy.allowedTriggerRoles must name at least one role.'], self::faults(self::policy(['allowedTriggerRoles' => []])));
		self::assertSame(
			['urgencyPolicy.allowedTriggerRoles names "burgemeester", which is not a recognised role (chair, vice-chair, secretary, treasurer, member, board-member).'],
			self::faults(self::policy(['allowedTriggerRoles' => ['chair', 'burgemeester']]))
		);
	}

	public function testTheFloorIsWholeHoursOfAtLeastZero(): void {
		self::assertSame([], self::faults(self::policy(['minimumNoticeFloorHours' => 0])));
		self::assertSame(['urgencyPolicy.minimumNoticeFloorHours must be a whole number of hours, 0 or more.'], self::faults(self::policy(['minimumNoticeFloorHours' => -1])));
		self::assertSame(['urgencyPolicy.minimumNoticeFloorHours must be a whole number of hours, 0 or more.'], self::faults(self::policy(['minimumNoticeFloorHours' => 1.5])));
	}

	public function testANonObjectPolicyIsRefused(): void {
		self::assertSame(['urgencyPolicy must be an object.'], self::faults('chair'));
		self::assertSame(['urgencyPolicy must be an object.'], self::faults(['chair']));
	}
}
