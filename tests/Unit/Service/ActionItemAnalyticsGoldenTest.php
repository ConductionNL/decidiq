<?php

/**
 * Golden runs of ActionItemAnalyticsService::getMyItems().
 *
 * Records every object-service call and log line, and the grouped result,
 * for a participant found by uuid or by id, no participant, items without
 * or with past, near and far due dates, and a failing lookup. Due dates are
 * relative to today, so the run does not depend on the date it runs on.
 * Pinned before getMyItems was split into named steps.
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
 * @spec exclude golden test of the my-items grouping, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActionItemAnalyticsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ActionItemAnalyticsGoldenTest extends TestCase {

	/**
	 * Everything the service did, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * A participant found by uuid: every item lands in its due-date group.
	 *
	 * @return void
	 */
	public function testItemsAreGroupedByDueDate(): void {
		$items = [
			['id' => 'past', 'dueDate' => $this->day('-3 days')],
			['id' => 'none', 'dueDate' => ''],
			['id' => 'soon', 'dueDate' => $this->day('+3 days')],
			['id' => 'missing'],
			['id' => 'far', 'dueDate' => $this->day('+20 days')],
			['id' => 'null', 'dueDate' => null],
			['id' => 'eight', 'dueDate' => $this->day('+8 days')],
		];
		$result = $this->service([[['uuid' => 'p-uuid', 'id' => 'p-id'], ['uuid' => 'other']], $items])->getMyItems('alice');

		self::assertSame(['past'], array_column($result['overdue'], 'id'));
		self::assertSame(['soon'], array_column($result['thisWeek'], 'id'));
		self::assertSame(['none', 'missing', 'far', 'null', 'eight'], array_column($result['later'], 'id'));
		self::assertSame(['overdue', 'thisWeek', 'later'], array_keys($result));
		self::assertSame(
			[
				['setRegister' => 'decidiq'],
				['setSchema' => 'participant'],
				['findAll' => ['filters' => ['nextcloudUserId' => 'alice']]],
				['setRegister' => 'decidiq'],
				['setSchema' => 'action-item'],
				['findAll' => ['filters' => ['assignee' => 'p-uuid', 'taskStatus' => ['open', 'in-progress'], '_limit' => 999, '_offset' => 0]]],
			],
			$this->trace
		);
	}//end testItemsAreGroupedByDueDate()

	/**
	 * A participant without a uuid is found by id; one without either finds nothing.
	 *
	 * @return void
	 */
	public function testTheParticipantFallsBackToItsId(): void {
		$this->service([[['id' => 'p-id']], []])->getMyItems('bob');
		self::assertSame('p-id', $this->trace[5]['findAll']['filters']['assignee']);

		$this->trace = [];
		$empty = $this->service([[['name' => 'no id']], []])->getMyItems('bob');
		self::assertSame(['overdue' => [], 'thisWeek' => [], 'later' => []], $empty);
		self::assertSame(['info' => 'ActionItemAnalyticsService::getMyItems: no participant found', 'context' => ['nextcloudUid' => 'bob']], $this->trace[3]);
		self::assertCount(4, $this->trace);
	}//end testTheParticipantFallsBackToItsId()

	/**
	 * No participant, and a failing lookup, both answer three empty groups.
	 *
	 * @return void
	 */
	public function testNothingIsFoundOrTheLookupFails(): void {
		$none = $this->service([[], []])->getMyItems('carol');
		self::assertSame(['overdue' => [], 'thisWeek' => [], 'later' => []], $none);
		self::assertSame('info', array_key_first($this->trace[3]));

		$this->trace = [];
		$failed = $this->service(null)->getMyItems('carol');
		self::assertSame(['overdue' => [], 'thisWeek' => [], 'later' => []], $failed);
		self::assertSame(['error' => 'ActionItemAnalyticsService::getMyItems failed: store down', 'context' => []], end($this->trace));

		$this->trace = [];
		$badDate = $this->service([[['uuid' => 'p']], [['id' => 'x', 'dueDate' => 'not a date']]])->getMyItems('carol');
		self::assertSame(['overdue' => [], 'thisWeek' => [], 'later' => []], $badDate);
		self::assertSame('error', array_key_first(end($this->trace)));
	}//end testNothingIsFoundOrTheLookupFails()

	/**
	 * A date relative to today, as the register stores it.
	 *
	 * @param string $offset The offset from today.
	 *
	 * @return string The date.
	 */
	private function day(string $offset): string {
		return date('Y-m-d', strtotime($offset));
	}//end day()

	/**
	 * The service over a recording object service.
	 *
	 * @param array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}|null $answers The participants and the items, or null to fail.
	 *
	 * @return ActionItemAnalyticsService The service.
	 */
	private function service(?array $answers): ActionItemAnalyticsService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnCallback(
			function (string $register) use ($objects): ObjectServiceInterface {
				$this->trace[] = ['setRegister' => $register];
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			function (string $schema) use ($objects): ObjectServiceInterface {
				$this->trace[] = ['setSchema' => $schema];
				return $objects;
			}
		);
		$calls = 0;
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($answers, &$calls): array {
				$this->trace[] = ['findAll' => $config];
				if ($answers === null) {
					throw new RuntimeException('store down');
				}

				$rows = $answers[$calls];
				$calls++;
				return array_map(
					function (array $row): ObjectEntity {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					},
					$rows
				);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new ActionItemAnalyticsService($logger, $objects);
	}//end service()
}//end class
