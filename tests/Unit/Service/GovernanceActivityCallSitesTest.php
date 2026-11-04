<?php

/**
 * What the decision services hand to the activity feed.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActivityPublisherService;
use OCA\Decidiq\Service\DecisionPublicationService;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recording a decision live and publishing a decision each publish one
 * activity entry: subject, title, status, object type, object uuid and route
 * segment, in that order, with no extra audience.
 *
 * @covers \OCA\Decidiq\Service\LiveDecisionService
 * @covers \OCA\Decidiq\Service\DecisionPublicationService
 */
class GovernanceActivityCallSitesTest extends TestCase {

	/**
	 * Every publishGovernanceEvent call, as the subject..segment list plus the audience.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $published = [];

	/**
	 * A decision recorded during a live meeting.
	 *
	 * @return void
	 */
	public function testRecordingADecisionPublishesItsOutcome(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturn($this->entity(data: ['id' => 'm-1', 'lifecycle' => 'opened']));
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturn(
			$this->entity(data: ['id' => 'd-7', 'title' => 'Begroting 2027', 'outcome' => 'adopted', '@self' => ['slug' => 'begroting-2027']])
		);

		$service = new LiveDecisionService(container: $this->container(), logger: $this->createMock(LoggerInterface::class), objectService: $objects);
		$service->recordDecision(meetingId: 'm-1', decisionData: ['title' => 'Begroting 2027', 'outcome' => 'adopted']);

		self::assertSame(
			[[['decision_recorded', 'Begroting 2027', 'adopted', 'decision', 'd-7', 'decisions'], []]],
			$this->published
		);

	}//end testRecordingADecisionPublishesItsOutcome()

	/**
	 * A decision recorded without title, outcome or id falls back to its slug.
	 *
	 * @return void
	 */
	public function testRecordingAnUntitledDecisionFallsBackToItsSlug(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturn($this->entity(data: ['id' => 'm-1', 'lifecycle' => 'opened']));
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturn($this->entity(data: ['@self' => ['slug' => 'naamloos', 'id' => 'self-9']]));

		$service = new LiveDecisionService(container: $this->container(), logger: $this->createMock(LoggerInterface::class), objectService: $objects);
		$service->recordDecision(meetingId: 'm-1', decisionData: []);

		self::assertSame([[['decision_recorded', 'naamloos', '', 'decision', 'self-9', 'decisions'], []]], $this->published);

	}//end testRecordingAnUntitledDecisionFallsBackToItsSlug()

	/**
	 * A published decision.
	 *
	 * @return void
	 */
	public function testPublishingADecisionAnnouncesItAsPublic(): void {
		$service = new DecisionPublicationService(container: $this->container(), logger: $this->createMock(LoggerInterface::class));
		$publish = new \ReflectionMethod($service, 'publishActivity');
		$publish->invoke($service, 'Begroting 2027', 'd-7');

		self::assertSame([[['decision_published', 'Begroting 2027', 'public', 'decision', 'd-7', 'decisions'], []]], $this->published);

	}//end testPublishingADecisionAnnouncesItAsPublic()

	/**
	 * A container whose activity publisher records each call, in either call shape.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$publisher = $this->createMock(ActivityPublisherService::class);
		$publisher->method('publishGovernanceEvent')->willReturnCallback(
			function (mixed ...$args): int {
				if (is_array($args[0]) === true) {
					$this->published[] = [array_values($args[0]), ($args[1] ?? [])];
					return 1;
				}

				$this->published[] = [array_slice($args, 0, 6), ($args[6] ?? [])];
				return 1;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($publisher);
		return $container;

	}//end container()

	/**
	 * An OpenRegister object double that serialises to $data.
	 *
	 * @param array<string, mixed> $data The object data
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()
}//end class
