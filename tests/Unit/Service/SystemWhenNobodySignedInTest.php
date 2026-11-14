<?php

/**
 * SystemWhenNobodySignedIn: elevate only where there is no user.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SystemWhenNobodySignedIn;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SystemWhenNobodySignedIn.
 */
class SystemWhenNobodySignedInTest extends TestCase {

	/**
	 * From cron or occ the operation runs through runAsSystem().
	 *
	 * @return void
	 */
	public function testWithNobodySignedInTheOperationRunsAsTheSystem(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation): mixed => $operation()
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$result = (new SystemWhenNobodySignedIn($objectService, $session))->run(operation: static fn (): int => 3);

		self::assertSame(3, $result);
	}//end testWithNobodySignedInTheOperationRunsAsTheSystem()

	/**
	 * Control: a signed-in user keeps their own rights.
	 *
	 * @return void
	 */
	public function testASignedInUserIsNotElevated(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::never())->method('runAsSystem');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$result = (new SystemWhenNobodySignedIn($objectService, $session))->run(operation: static fn (): int => 5);

		self::assertSame(5, $result);
	}//end testASignedInUserIsNotElevated()
}//end class
