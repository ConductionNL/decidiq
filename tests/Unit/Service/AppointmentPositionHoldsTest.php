<?php

/**
 * Unit tests for AppointmentPositionHolds.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AppointmentPositionHolds;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The position hold an enacted appointment gives each new member.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class AppointmentPositionHoldsTest extends TestCase {

	/**
	 * An appointment of body b-1, effective 10 April 2026, by decision d-1.
	 *
	 * @var array{body: string, startDate: string, decision: string}
	 */
	private const APPOINTMENT = ['body' => 'b-1', 'startDate' => '2026-04-10', 'decision' => 'd-1'];

	/**
	 * The hold names the membership, the position, the body, the day and the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testTheHoldCarriesWhoWhatFromWhenAndByWhichDecision(): void {
		self::assertSame(
			expected: [
				'membership' => 'm-1',
				'position' => 'pt-chair',
				'governanceBody' => 'body-1',
				'holdType' => 'regular',
				'startDate' => '2026-04-10',
				'appointedBy' => 'dec-1',
			],
			actual: AppointmentPositionHolds::payload(
				membershipId: 'm-1',
				positionId: 'pt-chair',
				appointment: ['body' => 'body-1', 'startDate' => '2026-04-10T19:00:00+02:00', 'decision' => 'dec-1']
			)
		);

	}//end testTheHoldCarriesWhoWhatFromWhenAndByWhichDecision()

	/**
	 * A role-only appointment, or one with no start, writes no hold.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testNoPositionOrNoStartWritesNothing(): void {
		self::assertNull(actual: AppointmentPositionHolds::payload(membershipId: 'm-1', positionId: null, appointment: self::APPOINTMENT));
		self::assertNull(actual: AppointmentPositionHolds::payload(membershipId: 'm-1', positionId: '', appointment: self::APPOINTMENT));
		$undated = ['startDate' => ''] + self::APPOINTMENT;
		self::assertNull(actual: AppointmentPositionHolds::payload(membershipId: 'm-1', positionId: 'pt', appointment: $undated));
		self::assertNull(actual: AppointmentPositionHolds::payload(membershipId: '', positionId: 'pt', appointment: self::APPOINTMENT));

	}//end testNoPositionOrNoStartWritesNothing()

	/**
	 * An unknown body is left off rather than written empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAnUnknownBodyIsLeftOff(): void {
		$hold = AppointmentPositionHolds::payload(membershipId: 'm-1', positionId: 'pt', appointment: ['body' => null] + self::APPOINTMENT);

		self::assertIsArray(actual: $hold);
		self::assertArrayNotHasKey(key: 'governanceBody', array: $hold);

	}//end testAnUnknownBodyIsLeftOff()

	/**
	 * The hold is saved on the position-hold schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testWriteSavesOnThePositionHoldSchema(): void {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'hold-1']);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			static function (array $object, ObjectSaveOptions $options) use ($entity) {
				self::assertSame(expected: 'decidiq', actual: $options->target->register);
				self::assertSame(expected: 'position-hold', actual: $options->target->schema);
				self::assertSame(expected: 'pt-1', actual: $object['position']);
				return $entity;
			}
		);

		$holds = new AppointmentPositionHolds(objectService: $objectService, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$holds->write(membershipId: 'm-1', positionId: 'pt-1', appointment: self::APPOINTMENT);

	}//end testWriteSavesOnThePositionHoldSchema()

	/**
	 * A role-only appointment never reaches the store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testWriteWithoutAPositionSavesNothing(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects(self::never())->method('saveObject');

		$holds = new AppointmentPositionHolds(objectService: $objectService, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$holds->write(membershipId: 'm-1', positionId: null, appointment: self::APPOINTMENT);

	}//end testWriteWithoutAPositionSavesNothing()

	/**
	 * A failed save is logged, never thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testAFailedSaveIsLoggedNotThrown(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('position not on this body'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$holds = new AppointmentPositionHolds(objectService: $objectService, logger: $logger);

		$holds->write(membershipId: 'm-1', positionId: 'pt-1', appointment: self::APPOINTMENT);

	}//end testAFailedSaveIsLoggedNotThrown()
}//end class
