<?php

/**
 * Characterisation tests for NotificationPreferenceService's lookups.
 *
 * Pins the branches of findPreference(), getActiveDelegate() and
 * getGovernanceEmail() that the main service test leaves open: the failing
 * lookup, entity versus array rows, blank delegation fields, timestamp
 * dates and the failing account-email lookup.
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
 * @spec openspec/specs/user-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation tests for the preference lookups.
 */
class NotificationPreferenceLookupTest extends TestCase {

	/**
	 * Build the service over a fixed list of findAll() results.
	 *
	 * @param array<int, mixed>    $results  What findAll() returns
	 * @param array<string, mixed> $services Extra container entries
	 * @param LoggerInterface|null $logger   Logger to observe
	 *
	 * @return NotificationPreferenceService
	 */
	private function serviceOver(array $results, array $services = [], ?LoggerInterface $logger = null): NotificationPreferenceService {
		$objectService = new class($results) {

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $results Rows to return.
			 */
			public function __construct(
				private array $results,
			) {
			}

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * Find-all returning the fixed rows.
			 *
			 * @param array $config Find-all config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				return $this->results;
			}
		};

		$services['OCA\OpenRegister\Service\ObjectService'] = $objectService;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new RuntimeException('not found: ' . $id);
			}
		);

		return new NotificationPreferenceService(container: $container, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end serviceOver()

	/**
	 * A failing ObjectService lookup is logged at debug level and yields null.
	 *
	 * @return void
	 */
	public function testFailingLookupIsLoggedAndYieldsNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'Decidiq: findPreference failed',
			['personId' => 'alice', 'error' => 'no OpenRegister']
		);
		$service = new NotificationPreferenceService(container: $container, logger: $logger);

		self::assertNull($service->findPreference(personId: 'alice'));
	}//end testFailingLookupIsLoggedAndYieldsNull()

	/**
	 * The first entity or array row wins; scalars are skipped; nothing is null.
	 *
	 * @return void
	 */
	public function testFirstEntityOrArrayRowWins(): void {
		$entity = new class {

			/**
			 * The stored object.
			 *
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['person' => 'from-entity'];
			}
		};

		self::assertSame(['person' => 'from-entity'], $this->serviceOver(['skip', $entity, ['person' => 'row']])->findPreference(personId: 'x'));
		self::assertSame(['person' => 'row'], $this->serviceOver([42, new \stdClass(), ['person' => 'row']])->findPreference(personId: 'x'));
		self::assertNull($this->serviceOver(['skip', 7])->findPreference(personId: 'x'));
		self::assertNull($this->serviceOver([])->findPreference(personId: 'x'));
	}//end testFirstEntityOrArrayRowWins()

	/**
	 * Missing, blank or non-string delegates and blank end dates are never active.
	 *
	 * @return void
	 */
	public function testBlankDelegationFieldsAreNeverActive(): void {
		$today = new DateTimeImmutable('2026-07-07');
		$rows = [
			['delegationFrom' => '2026-07-01', 'delegationUntil' => '2026-07-14'],
			['delegate' => '', 'delegationUntil' => '2026-07-14'],
			['delegate' => 12, 'delegationUntil' => '2026-07-14'],
			['delegate' => 'memberB', 'delegationUntil' => ''],
			['delegate' => 'memberB', 'delegationUntil' => 20260714],
		];
		foreach ($rows as $index => $row) {
			self::assertNull($this->serviceOver([$row])->getActiveDelegate(personId: 'memberA', today: $today), 'row ' . $index);
		}

		self::assertNull($this->serviceOver([])->getActiveDelegate(personId: 'memberA', today: $today));
	}//end testBlankDelegationFieldsAreNeverActive()

	/**
	 * A blank or non-string start date means "already started"; timestamps compare on their date.
	 *
	 * @return void
	 */
	public function testStartDateAndTimestampHandling(): void {
		$today = new DateTimeImmutable('2026-07-07');
		$active = [
			['delegate' => 'memberB', 'delegationFrom' => '', 'delegationUntil' => '2026-07-14'],
			['delegate' => 'memberB', 'delegationFrom' => 20260801, 'delegationUntil' => '2026-07-14'],
			['delegate' => 'memberB', 'delegationFrom' => '2026-07-07T23:00:00Z', 'delegationUntil' => '2026-07-07T00:00:00Z'],
		];
		foreach ($active as $index => $row) {
			self::assertSame('memberB', $this->serviceOver([$row])->getActiveDelegate(personId: 'memberA', today: $today), 'row ' . $index);
		}

		$inactive = [
			['delegate' => 'memberB', 'delegationFrom' => '2026-07-08T00:00:00Z', 'delegationUntil' => '2026-07-14'],
			['delegate' => 'memberB', 'delegationUntil' => '2026-07-06T23:59:59Z'],
		];
		foreach ($inactive as $index => $row) {
			self::assertNull($this->serviceOver([$row])->getActiveDelegate(personId: 'memberA', today: $today), 'row ' . $index);
		}
	}//end testStartDateAndTimestampHandling()

	/**
	 * A blank override falls back to the account; a blank account email is null.
	 *
	 * @return void
	 */
	public function testBlankOverrideAndBlankAccountEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('personal@example.com');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$service = $this->serviceOver([['governanceEmail' => '']], [IUserManager::class => $users]);
		self::assertSame('personal@example.com', $service->getGovernanceEmail(personId: 'alice'));

		$blank = $this->createMock(IUser::class);
		$blank->method('getEMailAddress')->willReturn('');
		$blankUsers = $this->createMock(IUserManager::class);
		$blankUsers->method('get')->willReturn($blank);
		self::assertNull($this->serviceOver([], [IUserManager::class => $blankUsers])->getGovernanceEmail(personId: 'alice'));
	}//end testBlankOverrideAndBlankAccountEmail()

	/**
	 * A failing account lookup is logged at debug level and yields null.
	 *
	 * @return void
	 */
	public function testFailingAccountLookupIsLoggedAndYieldsNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'Decidiq: account email lookup failed',
			['personId' => 'alice', 'error' => 'not found: OCP\IUserManager']
		);

		self::assertNull($this->serviceOver([], [], $logger)->getGovernanceEmail(personId: 'alice'));
	}//end testFailingAccountLookupIsLoggedAndYieldsNull()
}//end class
