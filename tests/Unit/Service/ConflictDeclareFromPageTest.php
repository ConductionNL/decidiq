<?php

/**
 * Unit tests for declaring a conflict of interest from a motion or agenda item page.
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
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidiq\Service\ConflictOfInterestService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A declaration from the page: the member's own Membership, an optional
 * recusal, and a motion as the subject.
 *
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 */
class ConflictDeclareFromPageTest extends TestCase {

	/**
	 * Saved objects.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Wrap an array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The service over a schema-aware double: motion M-12 is a decision of
	 * meeting-1, Anna (anna) is participant P-anna = Membership M-anna and
	 * Carla (carla) chairs meeting-1.
	 *
	 * @return ConflictOfInterestService
	 */
	private function service(): ConflictOfInterestService {
		$bySchema = [
			'decision' => ['M-12' => ['id' => 'M-12', 'meeting' => 'meeting-1']],
			'agenda-item' => [],
		];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($bySchema): ?ObjectEntity {
				$row = ($bySchema[(string)$schema][$id] ?? null);
				return $row === null ? null : $this->entity($row);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['filters']['nextcloudUserId'] ?? '') === 'anna') {
					return [$this->entity(['uuid' => 'P-anna', 'nextcloudUserId' => 'anna'])];
				}

				return [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity(array_merge(['id' => 'decl-1'], $object));
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		$participantResolver->method('hasRole')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid): bool => $meetingId === 'meeting-1' && $nextcloudUid === 'carla'
		);
		$crosswalk = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$crosswalk->method('resolve')->willReturnCallback(
			static fn (string $participantId): array => ['person' => 'person-anna', 'membership' => str_replace('P-', 'M-', $participantId)]
		);

		$logger = new NullLogger();
		return new ConflictOfInterestService(
			logger: $logger,
			auditLogService: $this->createMock(AuditLogService::class),
			objectService: $objectService,
			authorizationGuard: new ConflictOfInterestAuthorizationGuard(
				logger: $logger,
				objectService: $objectService,
				participantResolver: $participantResolver,
				participantCrosswalk: $crosswalk,
			),
		);
	}//end service()

	/**
	 * The page does not know the member's Membership: the service finds it from her login.
	 *
	 * @return void
	 */
	public function testTheMembershipOfTheLoggedInMemberIsFound(): void {
		$service = $this->service();

		$this->assertSame('M-anna', $service->membershipForUser('anna'));
		$this->assertNull($service->membershipForUser('stranger'));
	}//end testTheMembershipOfTheLoggedInMemberIsFound()

	/**
	 * A member who ticks "I will not vote on this" is recorded as recused from the vote.
	 *
	 * @return void
	 */
	public function testAMemberCanRecuseHerselfWhenDeclaring(): void {
		$result = $this->service()->declare('M-anna', 'M-12', 'financial-interest', 'Owns land in the plan area', 'material', 'anna', true);

		$this->assertTrue($result['success']);
		$this->assertSame('recused-from-vote', $this->saved[0]['actionTaken']);
		$this->assertSame('M-12', $this->saved[0]['agendaItem']);
	}//end testAMemberCanRecuseHerselfWhenDeclaring()

	/**
	 * Without the tick the declaration waits for the chair's decision.
	 *
	 * @return void
	 */
	public function testWithoutRecusalTheActionStaysOpen(): void {
		$this->service()->declare('M-anna', 'M-12', 'financial-interest', 'Owns land', 'material', 'anna');

		$this->assertSame('no-action-needed', $this->saved[0]['actionTaken']);
	}//end testWithoutRecusalTheActionStaysOpen()

	/**
	 * The chair of the motion's meeting may declare on a motion, as on an agenda item.
	 *
	 * @return void
	 */
	public function testTheChairMayDeclareOnAMotionOfHerMeeting(): void {
		$result = $this->service()->declare('M-anna', 'M-12', 'financial-interest', 'Owns land', 'material', 'carla');

		$this->assertTrue($result['success'], $result['message']);
	}//end testTheChairMayDeclareOnAMotionOfHerMeeting()
}//end class
