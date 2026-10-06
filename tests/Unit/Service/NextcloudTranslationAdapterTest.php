<?php

/**
 * Unit tests for NextcloudTranslationAdapter.
 *
 * Minutes translate through Nextcloud TaskProcessing, and without a
 * translation provider the adapter fails instead of handing back the
 * source text as if it were a translation (#1382).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\NextcloudTranslationAdapter;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for NextcloudTranslationAdapter.
 *
 * @covers \OCA\Decidiq\Service\NextcloudTranslationAdapter
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class NextcloudTranslationAdapterTest extends TestCase {

	/**
	 * An adapter whose container answers with the given TaskProcessing manager, or with nothing.
	 *
	 * @param IManager|null $manager The manager, or null for a container without one.
	 *
	 * @return NextcloudTranslationAdapter
	 */
	private function adapter(?IManager $manager): NextcloudTranslationAdapter {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($manager === null) {
			$container->method('get')->willThrowException(new RuntimeException('no TaskProcessing'));
		} else {
			$container->method('get')->with(IManager::class)->willReturn($manager);
		}

		return new NextcloudTranslationAdapter(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end adapter()

	/**
	 * A manager that offers the translate task type, with a provider named `translate2`.
	 *
	 * @return IManager&MockObject
	 */
	private function managerWithTranslate(): IManager&MockObject {
		$provider = $this->createMock(originalClassName: IProvider::class);
		$provider->method('getId')->willReturn('translate2');

		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn([TextToTextTranslate::ID]);
		$manager->method('getPreferredProvider')->willReturn($provider);

		return $manager;
	}//end managerWithTranslate()

	/**
	 * Identical locales short-circuit with provider=noop.
	 *
	 * @return void
	 */
	public function testIdenticalLocalesShortCircuit(): void {
		$result = $this->adapter(manager: null)->translate('hello', 'nl', 'nl');

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: 'hello', actual: $result['text']);
		$this->assertSame(expected: 'noop', actual: $result['provider']);
	}//end testIdenticalLocalesShortCircuit()

	/**
	 * Without TaskProcessing the translation fails rather than passing the source off as translated.
	 *
	 * @return void
	 */
	public function testWithoutTaskProcessingTheTranslationFails(): void {
		$adapter = $this->adapter(manager: null);

		$result = $adapter->translate('Hallo wereld', 'nl', 'en');

		$this->assertFalse(condition: $adapter->isAvailable());
		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'none', actual: $result['provider']);
		$this->assertStringContainsString(needle: 'No Nextcloud translation provider', haystack: $result['message']);
	}//end testWithoutTaskProcessingTheTranslationFails()

	/**
	 * Without a translate provider the translation fails and no task runs.
	 *
	 * @return void
	 */
	public function testWithoutATranslateProviderTheTranslationFails(): void {
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn(['core:text2text']);
		$manager->expects($this->never())->method('runTask');

		$result = $this->adapter(manager: $manager)->translate('Hallo wereld', 'nl', 'en');

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'none', actual: $result['provider']);
	}//end testWithoutATranslateProviderTheTranslationFails()

	/**
	 * With a translate provider the text is translated through a TaskProcessing task.
	 *
	 * @return void
	 */
	public function testTranslatesThroughTaskProcessing(): void {
		$manager = $this->managerWithTranslate();
		$manager->expects($this->once())->method('runTask')->willReturnCallback(
			function (Task $task): Task {
				$this->assertSame(expected: TextToTextTranslate::ID, actual: $task->getTaskTypeId());
				$this->assertSame(
					expected: ['input' => 'Hallo wereld', 'origin_language' => 'nl', 'target_language' => 'en'],
					actual: $task->getInput()
				);
				$task->setOutput(['output' => 'Hello world']);
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				return $task;
			}
		);

		$adapter = $this->adapter(manager: $manager);
		$result = $adapter->translate('Hallo wereld', 'nl', 'en');

		$this->assertTrue(condition: $adapter->isAvailable());
		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: 'Hello world', actual: $result['text']);
		$this->assertSame(expected: 'translate2', actual: $result['provider']);
	}//end testTranslatesThroughTaskProcessing()

	/**
	 * A task that fails reports failure with the provider's error.
	 *
	 * @return void
	 */
	public function testAFailedTaskReportsFailure(): void {
		$manager = $this->managerWithTranslate();
		$manager->method('runTask')->willReturnCallback(
			static function (Task $task): Task {
				$task->setStatus(Task::STATUS_FAILED);
				$task->setErrorMessage('model not loaded');
				return $task;
			}
		);

		$result = $this->adapter(manager: $manager)->translate('Hallo wereld', 'nl', 'en');

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'Hallo wereld', actual: $result['text']);
		$this->assertStringContainsString(needle: 'model not loaded', haystack: $result['message']);
	}//end testAFailedTaskReportsFailure()

	/**
	 * A task run that throws reports failure instead of escaping.
	 *
	 * @return void
	 */
	public function testAThrowingTaskRunReportsFailure(): void {
		$manager = $this->managerWithTranslate();
		$manager->method('runTask')->willThrowException(new RuntimeException('provider crashed'));

		$result = $this->adapter(manager: $manager)->translate('Hallo wereld', 'nl', 'en');

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'translate2', actual: $result['provider']);
		$this->assertStringContainsString(needle: 'provider crashed', haystack: $result['message']);
	}//end testAThrowingTaskRunReportsFailure()
}//end class
