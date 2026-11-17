<?php

/**
 * Failure paths of ApprovalStageTaskProjector.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/parafering-route-runtime/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalStageTaskProjector;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The task inbox is a mirror of the stages: a failing store write, a failing
 * task surface or a task without a uuid is logged and costs only that one
 * stage's projection.
 *
 * @covers \OCA\Decidiq\Service\ApprovalStageTaskProjector
 */
class ApprovalStageTaskProjectorFailureTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store  = $this->createMock(RegisterObjectStore::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A projector whose container resolves the given task surface.
	 *
	 * @param object $tasks The task surface
	 *
	 * @return ApprovalStageTaskProjector
	 */
	private function projector(object $tasks): ApprovalStageTaskProjector {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\Task\\TaskService')->willReturn($tasks);
		return new ApprovalStageTaskProjector(store: $this->store, container: $container, logger: $this->logger);
	}//end projector()

	/**
	 * A task surface whose import answers the given uuids in turn, or throws
	 * for a stage whose assignee is "broken".
	 *
	 * @param list<string> $uuids The uuids to answer
	 *
	 * @return object
	 */
	private function tasks(array $uuids=['task-1']): object {
		return new class($uuids) {
			/**
			 * @var list<string>
			 */
			public array $assignees = [];

			/**
			 * @param list<string> $uuids The uuids to answer
			 */
			public function __construct(private array $uuids) {
			}

			/**
			 * @param array<string, mixed> $data  The task
			 * @param string|null          $actor The actor
			 *
			 * @return object
			 */
			public function import(array $data, ?string $actor): object {
				if ($data['assignee'] === 'broken') {
					throw new RuntimeException('surface refused');
				}

				$this->assignees[] = $data['assignee'];
				$uuid = array_shift($this->uuids);
				return new class($uuid) {
					/**
					 * @param string $uuid The uuid
					 */
					public function __construct(private string $uuid) {
					}

					/**
					 * @return string
					 */
					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}

			/**
			 * @param string $uuid   The task
			 * @param string $source The source
			 * @param string $reason The reason
			 *
			 * @return void
			 */
			public function consume(string $uuid, string $source, string $reason): void {
				throw new RuntimeException('already terminal');
			}
		};
	}//end tasks()

	/**
	 * When recording who a stage waits on fails, the failure is logged and the
	 * stage's task is still opened.
	 *
	 * @return void
	 */
	public function testAFailedWaitingOnWriteIsLoggedAndTheTaskStillOpens(): void {
		$patches = [];
		$this->store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) use (&$patches): array {
				if (array_key_exists('waitingOn', $data) === true) {
					throw new RuntimeException('store down');
				}
				$patches[] = [$schema, $data, $uuid];
				return $data;
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: could not record who an approval stage waits on',
			['subject' => 'v-1', 'stage' => 's-1', 'error' => 'store down']
		);

		$tasks = $this->tasks();
		$this->projector($tasks)->sync(
			subject: 'v-1',
			stages: [['id' => 's-1', 'status' => 'active', 'assignedPerson' => 'alice', 'taskUuid' => '']]
		);

		self::assertSame(['alice'], $tasks->assignees);
		self::assertSame([['decision-stage', ['taskUuid' => 'task-1'], 's-1']], $patches);
	}//end testAFailedWaitingOnWriteIsLoggedAndTheTaskStillOpens()

	/**
	 * A stage the task surface refuses is logged and the next stage still syncs;
	 * a stage without an id writes no waitingOn.
	 *
	 * @return void
	 */
	public function testARefusedTaskIsLoggedAndTheNextStageStillSyncs(): void {
		$patches = [];
		$this->store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) use (&$patches): array {
				$patches[] = [$data, $uuid];
				return $data;
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: could not mirror an approval stage onto the task surface',
			['subject' => 'v-1', 'stage' => 's-1', 'error' => 'surface refused']
		);

		$tasks = $this->tasks(['task-2']);
		$this->projector($tasks)->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'status' => 'active', 'assignedPerson' => 'broken', 'waitingOn' => 'broken', 'taskUuid' => ''],
				['id' => 's-2', 'status' => 'active', 'assignedPerson' => 'bob', 'waitingOn' => 'bob', 'taskUuid' => ''],
				['status' => 'pending'],
			]
		);

		self::assertSame(['bob'], $tasks->assignees);
		self::assertSame([[['taskUuid' => 'task-2'], 's-2']], $patches);
	}//end testARefusedTaskIsLoggedAndTheNextStageStillSyncs()

	/**
	 * A task answered without a uuid is logged and no linkage is written.
	 *
	 * @return void
	 */
	public function testATaskWithoutAUuidWritesNoLinkage(): void {
		$this->store->expects(self::never())->method('patch');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: the task surface returned a task with no uuid, so the stage cannot reference it',
			['subject' => 'v-1', 'stage' => 's-1']
		);

		$this->projector($this->tasks(['']))->sync(
			subject: 'v-1',
			stages: [['id' => 's-1', 'status' => 'active', 'assignedPerson' => 'alice', 'waitingOn' => 'alice', 'taskUuid' => '']]
		);
	}//end testATaskWithoutAUuidWritesNoLinkage()

	/**
	 * A task the surface refuses to consume is logged at info, and the linkage
	 * is cleared all the same.
	 *
	 * @return void
	 */
	public function testARefusedConsumeStillClearsTheLinkage(): void {
		$this->store->expects(self::once())->method('patch')->with(
			schema: 'decision-stage',
			data: ['taskUuid' => ''],
			uuid: 's-1'
		);
		$this->logger->expects(self::never())->method('warning');
		$this->logger->expects(self::once())->method('info')->with(
			'Decidiq: a projected approval task was not consumed (already terminal is the ordinary cause): already terminal',
			['task' => 'task-9']
		);

		$this->projector($this->tasks())->sync(
			subject: 'v-1',
			stages: [['id' => 's-1', 'status' => 'approved', 'taskUuid' => 'task-9']]
		);
	}//end testARefusedConsumeStillClearsTheLinkage()
}//end class
