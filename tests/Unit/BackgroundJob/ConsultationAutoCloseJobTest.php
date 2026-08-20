<?php

/**
 * Unit tests for ConsultationAutoCloseJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\BackgroundJob;

use OCA\Decidesk\BackgroundJob\ConsultationAutoCloseJob;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that the job closes open consultations past their submissionDeadline
 * and leaves future-deadline ones open.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ConsultationAutoCloseJobTest extends TestCase {

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Run the job and assert only past-deadline consultations are closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testClosesPastDeadlineConsultations(): void {
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
		$future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		// First page returns two open consultations; subsequent pages empty.
		$objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[
				$this->entity(['id' => 'c-past', 'status' => 'open', 'submissionDeadline' => $past]),
				$this->entity(['id' => 'c-future', 'status' => 'open', 'submissionDeadline' => $future]),
			],
			[]
		);

		$lifecycle = $this->createMock(ParticipationLifecycleService::class);
		$closed = [];
		$lifecycle->method('transitionConsultation')->willReturnCallback(
			function (string $consultationId, string $newStatus) use (&$closed) {
				$closed[] = $consultationId;
				return ['id' => $consultationId, 'status' => $newStatus];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $lifecycle) {
				if ($id === ParticipationLifecycleService::class) {
					return $lifecycle;
				}

				return $objectService;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(time());

		$job = new ConsultationAutoCloseJob(
			time: $timeFactory,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

		self::assertContains('c-past', $closed);
		self::assertNotContains('c-future', $closed);

	}//end testClosesPastDeadlineConsultations()

}//end class
