<?php

/**
 * OpenRegisterFiles — whether OpenRegister holds files here, and its file service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\Decidiq\Support\OpenRegisterFiles;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The installed check asks for openregister, and the file service comes from the container.
 *
 * @covers \OCA\Decidiq\Support\OpenRegisterFiles
 */
final class OpenRegisterFilesTest extends TestCase {

	/**
	 * The installed check asks the app manager about openregister and answers what it says.
	 *
	 * @return void
	 */
	public function testInstalledAsksForOpenRegister(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->expects(self::exactly(2))->method('isInstalled')->with('openregister')->willReturnOnConsecutiveCalls(true, false);

		$files = new OpenRegisterFiles(appManager: $manager, container: $this->createMock(ContainerInterface::class));

		self::assertTrue($files->installed());
		self::assertFalse($files->installed());
	}//end testInstalledAsksForOpenRegister()

	/**
	 * The file service is OpenRegister's, by its class name.
	 *
	 * @return void
	 */
	public function testTheFileServiceIsOpenRegisters(): void {
		$service = new stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')->with('OCA\\OpenRegister\\Service\\FileService')->willReturn($service);

		$files = new OpenRegisterFiles(appManager: $this->createMock(IAppManager::class), container: $container);

		self::assertSame($service, $files->fileService());
	}//end testTheFileServiceIsOpenRegisters()

	/**
	 * A container that cannot build the file service lets the failure through.
	 *
	 * @return void
	 */
	public function testAMissingFileServiceIsNotHidden(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no file service'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no file service');

		(new OpenRegisterFiles(appManager: $this->createMock(IAppManager::class), container: $container))->fileService();
	}//end testAMissingFileServiceIsNotHidden()
}//end class
