<?php

/**
 * Golden runs of the action item to VTODO task mapping.
 *
 * Pins the task data an action item becomes: the core fields, empty ones left
 * out, each status mapping, and every non-core key carried in the fields
 * blob in its own order. Pinned before the mapping was split into named
 * steps.
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
 * @spec exclude golden test of the action item task mapping, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActionItemWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ActionItemTaskDataGoldenTest extends TestCase {

	/**
	 * Every item maps the same.
	 *
	 * @return void
	 */
	public function testEveryItemMapsTheSame(): void {
		$writer = (new ReflectionClass(ActionItemWriter::class))->newInstanceWithoutConstructor();
		$map = (new ReflectionClass(ActionItemWriter::class))->getMethod('toTaskData');

		$full = $map->invoke($writer, [
			'decision' => 'd-1',
			'title' => 'T',
			'taskStatus' => 'in-progress',
			'description' => 'Do it',
			'dueDate' => '2026-01-01',
			'assignee' => 'p-1',
			'priority' => 3,
			'meeting' => 'm-1',
			'fields' => ['old' => true],
			'goal' => 'g-1',
			'id' => 'x',
		], 'Title');
		self::assertSame(
			[
				'summary' => 'Title',
				'status' => $map->invoke($writer, ['taskStatus' => 'in-progress'], 'x')['status'],
				'description' => 'Do it',
				'due' => '2026-01-01',
				'fields' => ['decision' => 'd-1', 'taskStatus' => 'in-progress', 'assignee' => 'p-1', 'meeting' => 'm-1', 'goal' => 'g-1'],
			],
			$full
		);

		$runs = [];
		foreach (['open', 'in-progress', 'completed', 'overdue', 'unknown', null] as $status) {
			$item = ($status === null) ? [] : ['taskStatus' => $status];
			$runs[] = $map->invoke($writer, $item, 'T');
		}

		$runs[] = $map->invoke($writer, ['description' => '', 'dueDate' => '0', 'title' => 'only core', 'summary' => 's', 'due' => 'd', 'status' => 'x', 'uid' => 'u', 'calendarId' => 'c', 'completed' => true, 'created' => 'now', 'objectUuid' => 'o', 'registerId' => 1, 'schemaId' => 2], 'T');
		self::assertSame(
			[
				['summary' => 'T', 'status' => 'NEEDS-ACTION', 'fields' => ['taskStatus' => 'open']],
				['summary' => 'T', 'status' => 'IN-PROCESS', 'fields' => ['taskStatus' => 'in-progress']],
				['summary' => 'T', 'status' => 'COMPLETED', 'fields' => ['taskStatus' => 'completed']],
				['summary' => 'T', 'status' => 'NEEDS-ACTION', 'fields' => ['taskStatus' => 'overdue']],
				['summary' => 'T', 'status' => 'NEEDS-ACTION', 'fields' => ['taskStatus' => 'unknown']],
				['summary' => 'T', 'status' => 'NEEDS-ACTION'],
				['summary' => 'T', 'status' => 'NEEDS-ACTION'],
			],
			$runs
		);
	}//end testEveryItemMapsTheSame()
}//end class
