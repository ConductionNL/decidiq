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
	 * The logger the command writes to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

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
		$this->logger      = $this->createMock(LoggerInterface::class);
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
			new MigrateActionItems(migration: $this->migration, userManager: $this->userManager, userSession: $this->userSession, logger: $this->logger)
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

	/**
	 * A user whose migration counts failures makes the run fail, though every user exists.
	 *
	 * @return void
	 */
	public function testCountedFailuresMakeTheRunFail(): void {
		$this->migration->method('pendingOwners')->willReturn(['alice', 'bob']);
		$this->migration->method('migrateUser')->willReturnCallback(
			static fn (string $userId): array => [
				'migrated' => 1,
				'delegations' => 0,
				'skipped' => 0,
				'failed' => ($userId === 'bob') ? 2 : 0,
			]
		);

		$tester = $this->runCommand();

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('bob: 1 migrated, 0 delegations applied, 0 skipped, 2 failed', $tester->getDisplay());
		self::assertSame(['alice', null, 'bob', null], $this->acting);
	}//end testCountedFailuresMakeTheRunFail()

	/**
	 * An unknown user and a stopped migration are each logged with the user, and nobody is impersonated for the unknown one.
	 *
	 * @return void
	 */
	public function testSkippedUsersAreLoggedWithTheirId(): void {
		$this->migration->method('pendingOwners')->willReturn(['ghost', 'alice']);
		$this->migration->method('migrateUser')->willThrowException(new RuntimeException('no calendar'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$tester = $this->runCommand();

		self::assertSame(
			[
				['Decidiq: legacy action items of an unknown user were left as they are', ['user' => 'ghost']],
				['Decidiq: action-item migration for a user stopped', ['user' => 'alice', 'error' => 'no calendar']],
			],
			$warnings
		);
		self::assertSame(['alice', null], $this->acting);
		self::assertSame(1, $tester->getStatusCode());
	}//end testSkippedUsersAreLoggedWithTheirId()

	/**
	 * --user for someone with nothing pending migrates nobody.
	 *
	 * @return void
	 */
	public function testTheUserOptionForSomeoneWithNothingPendingIsANoOp(): void {
		$this->migration->method('pendingOwners')->willReturn(['alice']);
		$this->migration->expects(self::never())->method('migrateUser');

		$tester = $this->runCommand(['--user' => 'carol']);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('No legacy action items to migrate.', $tester->getDisplay());
		self::assertSame([], $this->acting);
	}//end testTheUserOptionForSomeoneWithNothingPendingIsANoOp()

	/**
	 * The command is decidiq:migrate-action-items with a --user/-u option that needs a value.
	 *
	 * @return void
	 */
	public function testTheCommandNameAndItsUserOption(): void {
		$command = new MigrateActionItems(migration: $this->migration, userManager: $this->userManager, userSession: $this->userSession, logger: $this->logger);
		$option  = $command->getDefinition()->getOption('user');

		self::assertSame('decidiq:migrate-action-items', $command->getName());
		self::assertSame('u', $option->getShortcut());
		self::assertTrue($option->isValueRequired());
		self::assertNotSame('', $command->getDescription());
	}//end testTheCommandNameAndItsUserOption()
}//end class
