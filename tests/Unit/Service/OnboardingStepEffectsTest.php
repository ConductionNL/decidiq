<?php

/**
 * Unit tests for OnboardingStepEffects: the membership effects of the
 * installation and end-membership checklist steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\Decidiq\Service\OnboardingStepEffects;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\Decidiq\Tests\Support\LegacyObjectArgs;

/**
 * Installation creates the membership once; an end step end-dates it.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
 */
#[CoversClass(OnboardingStepEffects::class)]
#[UsesClass(OnboardingStepRefusedException::class)]
class OnboardingStepEffectsTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The service under test.
	 *
	 * @var OnboardingStepEffects
	 */
	private OnboardingStepEffects $effects;

	/**
	 * Build the effects over a mocked object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->effects       = new OnboardingStepEffects(objectService: $this->objectService);
	}//end setUp()

	/**
	 * An entity double with a uuid and data.
	 *
	 * @param string|null          $uuid The uuid.
	 * @param array<string, mixed> $data The stored data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?string $uuid, array $data=[]): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A fully recorded onboarding.
	 *
	 * @return array<string, mixed>
	 */
	private static function onboarding(): array {
		return [
			'person' => 'person-1',
			'targetBody' => 'body-1',
			'targetRole' => 'member',
			'installedOn' => '2026-03-01',
			'installationType' => 'oath',
			'installationMeeting' => 'meeting-1',
		];
	}//end onboarding()

	/**
	 * Completing an installation creates the membership and links it.
	 *
	 * @return void
	 */
	public function testInstallationCreatesMembership(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
					'object' => [
					'person' => 'person-1',
					'governanceBody' => 'body-1',
					'role' => 'member',
					'startDate' => '2026-03-01',
				],
					'extend' => [],
					'register' => 'decidiq',
					'schema' => 'membership',
					'uuid' => null,
					'_rbac' => false,
					'_multitenancy' => false,
				]))
			->willReturn($this->entity('membership-9'));

		$result = $this->effects->apply(record: self::onboarding(), effect: 'onboarding:installation');

		$this->assertSame(self::onboarding() + ['membership' => 'membership-9'], $result);
	}//end testInstallationCreatesMembership()

	/**
	 * An installation that already has a membership creates no second one.
	 *
	 * @return void
	 */
	public function testInstallationWithMembershipIsIdempotent(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$record = (self::onboarding() + ['membership' => 'membership-1']);

		$this->assertSame($record, $this->effects->apply(record: $record, effect: 'onboarding:installation'));
	}//end testInstallationWithMembershipIsIdempotent()

	/**
	 * An installation missing its meeting is refused with 409.
	 *
	 * @return void
	 */
	public function testInstallationWithoutMeetingIsRefused(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$record = self::onboarding();
		$record['installationMeeting'] = '  ';

		try {
			$this->effects->apply(record: $record, effect: 'onboarding:installation');
			$this->fail('Expected the installation to be refused.');
		} catch (OnboardingStepRefusedException $e) {
			$this->assertSame('Record the installation date, type and meeting before completing the installation.', $e->getMessage());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testInstallationWithoutMeetingIsRefused()

	/**
	 * An end step sets the membership's end date.
	 *
	 * @return void
	 */
	public function testEndMembershipSetsEndDate(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
					'id' => 'membership-1',
					'_extend' => [],
					'files' => false,
					'register' => 'decidiq',
					'schema' => 'membership',
					'_rbac' => false,
					'_multitenancy' => false,
				]))
			->willReturn($this->entity('membership-1', ['id' => 'membership-1', 'role' => 'member']));
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
					'object' => ['id' => 'membership-1', 'role' => 'member', 'endDate' => '2026-12-31'],
					'extend' => [],
					'register' => 'decidiq',
					'schema' => 'membership',
					'uuid' => 'membership-1',
					'_rbac' => false,
					'_multitenancy' => false,
				]))
			->willReturn($this->entity('membership-1'));

		$record = ['membership' => 'membership-1', 'endDate' => '2026-12-31'];
		$this->assertSame($record, $this->effects->apply(record: $record, effect: 'offboarding:end-membership'));
	}//end testEndMembershipSetsEndDate()

	/**
	 * An end step without an end date is refused.
	 *
	 * @return void
	 */
	public function testEndMembershipWithoutEndDateIsRefused(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(OnboardingStepRefusedException::class);
		$this->expectExceptionMessage('Record the end date before ending the membership.');

		$this->effects->apply(record: ['membership' => 'membership-1', 'endDate' => ''], effect: 'offboarding:end-membership');
	}//end testEndMembershipWithoutEndDateIsRefused()

	/**
	 * An end step whose membership does not exist is refused.
	 *
	 * @return void
	 */
	public function testEndMembershipOfMissingMembershipIsRefused(): void {
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(OnboardingStepRefusedException::class);
		$this->expectExceptionMessage('The membership to end does not exist.');

		$this->effects->apply(record: ['membership' => 'membership-x', 'endDate' => '2026-12-31'], effect: 'offboarding:end-membership');
	}//end testEndMembershipOfMissingMembershipIsRefused()

	/**
	 * An end step with no membership id is refused even if a lookup answers.
	 *
	 * @return void
	 */
	public function testEndMembershipWithoutMembershipIdIsRefused(): void {
		$this->objectService->method('find')->willReturn($this->entity('m', ['id' => 'm']));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(OnboardingStepRefusedException::class);
		$this->expectExceptionMessage('The membership to end does not exist.');

		$this->effects->apply(record: ['endDate' => '2026-12-31'], effect: 'offboarding:end-membership');
	}//end testEndMembershipWithoutMembershipIdIsRefused()

	/**
	 * Any other step leaves the record untouched and the register alone.
	 *
	 * @return void
	 */
	public function testOtherStepHasNoEffect(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(['x' => 1], $this->effects->apply(record: ['x' => 1], effect: 'onboarding:welcome-pack'));
	}//end testOtherStepHasNoEffect()

	/**
	 * A lookup that throws reads as absent.
	 *
	 * @return void
	 */
	public function testFindDataReturnsNullWhenLookupThrows(): void {
		$this->objectService->expects($this->once())->method('find')->willThrowException(new \RuntimeException('boom'));

		$this->assertNull($this->effects->findData(id: 'm-1', schema: 'membership'));
	}//end testFindDataReturnsNullWhenLookupThrows()

	/**
	 * A found object returns its stored data.
	 *
	 * @return void
	 */
	public function testFindDataReturnsStoredData(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
					'id' => 'm-1',
					'_extend' => [],
					'files' => false,
					'register' => 'decidiq',
					'schema' => 'membership',
					'_rbac' => false,
					'_multitenancy' => false,
				]))
			->willReturn($this->entity('m-1', ['id' => 'm-1', 'role' => 'chair']));

		$this->assertSame(['id' => 'm-1', 'role' => 'chair'], $this->effects->findData(id: 'm-1', schema: 'membership'));
	}//end testFindDataReturnsStoredData()
}//end class
