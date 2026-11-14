<?php

/**
 * Test Suite for the MigrateActionItems command
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Command
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Command;

use OCA\Decidiq\Command\MigrateActionItems;
use OCA\Decidiq\Service\ActionItemMigrationService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command acts as each owner in turn and never leaves an acting user behind.
 *
 * @covers \OCA\Decidiq\Command\MigrateActionItems
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 */
class MigrateActionItemsTest extends TestCase {

	private ActionItemMigrationService&MockObject $migration;
	private IUserManager&MockObject $userManager;
	private IUserSession&MockObject $userSession;

	/**
	 * Session calls, in order.
	 *
	 * @var list<string|null>
	 */
	private array $acting = [];

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migration   = $this->createMock(ActionItemMigrationService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'ghost') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->acting[] = $user?->getUID();
			}
		);
	}//end setUp()

	/**
	 * Run the command.
	 *
	 * @param array<string, string> $input The input.
	 *
	 * @return CommandTester
	 */
	private function runCommand(array $input=[]): CommandTester {
		$tester = new CommandTester(
			new MigrateActionItems(migration: $this->migration, userManager: $this->userManager, userSession: $this->userSession, logger: $this->createMock(LoggerInterface::class))
		);
		$tester->execute($input);
		return $tester;
	}//end run()

	/**
	 * Each owner is migrated as that owner; an unknown one is skipped.
	 *
	 * @return void
	 */
	public function testEachOwnerIsMigratedAsThatOwner(): void {
		$this->migration->method('pendingOwners')->willReturn(['alice', 'ghost', 'bob']);
		$migrated = [];
		$this->migration->method('migrateUser')->willReturnCallback(
			function (string $userId) use (&$migrated): array {
				$migrated[] = [$userId, end($this->acting)];
				return ['migrated' => 2, 'delegations' => 1, 'skipped' => 0, 'failed' => 0];
			}
		);

		$tester = $this->runCommand();

		self::assertSame([['alice', 'alice'], ['bob', 'bob']], $migrated);
		self::assertSame(['alice', null, 'bob', null], $this->acting);
		self::assertStringContainsString('Skipped ghost: the user does not exist.', $tester->getDisplay());
		self::assertStringContainsString('alice: 2 migrated, 1 delegations applied, 0 skipped, 0 failed', $tester->getDisplay());
		self::assertSame(1, $tester->getStatusCode());
	}//end testEachOwnerIsMigratedAsThatOwner()

	/**
	 * A user whose migration throws is skipped and the acting user is still reset.
	 *
	 * @return void
	 */
	public function testAFailingUserIsSkippedAndTheSessionReset(): void {
		$this->migration->method('pendingOwners')->willReturn(['alice']);
		$this->migration->method('migrateUser')->willThrowException(new RuntimeException('no calendar'));

		$tester = $this->runCommand();

		self::assertSame(['alice', null], $this->acting);
		self::assertStringContainsString('Skipped alice: no calendar', $tester->getDisplay());
		self::assertSame(1, $tester->getStatusCode());
	}//end testAFailingUserIsSkippedAndTheSessionReset()

	/**
	 * Nothing pending is a clean no-op.
	 *
	 * @return void
	 */
	public function testNothingPendingIsANoOp(): void {
		$this->migration->method('pendingOwners')->willReturn([]);
		$this->migration->expects(self::never())->method('migrateUser');

		$tester = $this->runCommand();

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('No legacy action items to migrate.', $tester->getDisplay());
	}//end testNothingPendingIsANoOp()

	/**
	 * --user narrows the run to one owner.
	 *
	 * @return void
	 */
	public function testTheUserOptionNarrowsTheRun(): void {
		$this->migration->method('pendingOwners')->willReturn(['alice', 'bob']);
		$this->migration->expects(self::once())->method('migrateUser')->with('bob')->willReturn(['migrated' => 0, 'delegations' => 0, 'skipped' => 0, 'failed' => 0]);

		self::assertSame(0, $this->runCommand(['--user' => 'bob'])->getStatusCode());
	}//end testTheUserOptionNarrowsTheRun()
}//end class
