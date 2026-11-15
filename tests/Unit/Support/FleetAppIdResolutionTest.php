<?php

/**
 * FleetAppId — which installed id, path and service an app resolves to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\Decidiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The newest installed id wins, a failing candidate does not end the search,
 * and the path and service follow the id and namespace the instance has.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\Support\FleetAppId
 */
final class FleetAppIdResolutionTest extends TestCase {

	/**
	 * An app manager that has exactly these ids enabled, and fails for these.
	 *
	 * @param array<int, string> $enabled The enabled ids.
	 * @param array<int, string> $failing The ids it throws for.
	 *
	 * @return IAppManager
	 */
	private function appManager(array $enabled, array $failing = []): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isEnabledForAnyone')->willReturnCallback(
			static function (string $id) use ($enabled, $failing): bool {
				if (in_array($id, $failing, true) === true) {
					throw new RuntimeException('cannot answer for ' . $id);
				}

				return in_array($id, $enabled, true);
			}
		);

		return $manager;
	}//end appManager()

	/**
	 * The new id wins over the old when both are installed; the old is used alone.
	 *
	 * @return void
	 */
	public function testTheNewestInstalledIdWins(): void {
		self::assertSame('filinq', FleetAppId::resolve($this->appManager(['filinq', 'docudesk']), 'filinq'));
		self::assertSame('docudesk', FleetAppId::resolve($this->appManager(['docudesk']), 'filinq'));
		self::assertNull(FleetAppId::resolve($this->appManager([]), 'filinq'));
	}//end testTheNewestInstalledIdWins()

	/**
	 * An app with no rename resolves to its own id only.
	 *
	 * @return void
	 */
	public function testAnUnrenamedAppResolvesToItself(): void {
		self::assertSame('openregister', FleetAppId::resolve($this->appManager(['openregister']), 'openregister'));
		self::assertNull(FleetAppId::resolve($this->appManager(['docudesk']), 'openregister'));
	}//end testAnUnrenamedAppResolvesToItself()

	/**
	 * A candidate the manager cannot answer for is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testAFailingCandidateDoesNotEndTheSearch(): void {
		self::assertSame('openconnector', FleetAppId::resolve($this->appManager(['openconnector'], ['integriq']), 'integriq'));
		self::assertNull(FleetAppId::resolve($this->appManager([], ['integriq', 'openconnector']), 'integriq'));
	}//end testAFailingCandidateDoesNotEndTheSearch()

	/**
	 * Installed means some candidate resolves.
	 *
	 * @return void
	 */
	public function testIsInstalledFollowsResolve(): void {
		self::assertTrue(FleetAppId::isInstalled($this->appManager(['procest']), 'dossiq'));
		self::assertFalse(FleetAppId::isInstalled($this->appManager(['decidiq']), 'dossiq'));
	}//end testIsInstalledFollowsResolve()

	/**
	 * The user check runs against the resolved id, and is false when none resolves or it fails.
	 *
	 * @return void
	 */
	public function testTheUserCheckUsesTheResolvedId(): void {
		$manager = $this->appManager(['softwarecatalog']);
		$manager->expects(self::once())->method('isEnabledForUser')->with('softwarecatalog')->willReturn(true);
		self::assertTrue(FleetAppId::isEnabledForUser($manager, 'stackiq'));

		$absent = $this->appManager([]);
		$absent->expects(self::never())->method('isEnabledForUser');
		self::assertFalse(FleetAppId::isEnabledForUser($absent, 'stackiq'));

		$failing = $this->appManager(['stackiq']);
		$failing->method('isEnabledForUser')->willThrowException(new RuntimeException('no user'));
		self::assertFalse(FleetAppId::isEnabledForUser($failing, 'stackiq'));
	}//end testTheUserCheckUsesTheResolvedId()

	/**
	 * The path is mounted under the installed id, with the suffix joined by one slash.
	 *
	 * @return void
	 */
	public function testThePathUsesTheInstalledId(): void {
		$manager = $this->appManager(['openconnector']);

		self::assertSame('/apps/openconnector', FleetAppId::appPath($manager, 'integriq'));
		self::assertSame('/apps/openconnector/api/sources', FleetAppId::appPath($manager, 'integriq', '/api/sources'));
		self::assertSame('/apps/openconnector/api/sources', FleetAppId::appPath($manager, 'integriq', 'api/sources'));
		self::assertNull(FleetAppId::appPath($this->appManager([]), 'integriq', 'api/sources'));
	}//end testThePathUsesTheInstalledId()

	/**
	 * A service is fetched under the newest namespace the container knows, skipping failures.
	 *
	 * @return void
	 */
	public function testTheServiceIsFetchedUnderTheNamespaceTheContainerKnows(): void {
		$service = new stdClass();
		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, &$asked): object {
				$asked[] = $id;
				if ($id === 'OCA\DocuDesk\Service\PdfService') {
					return $service;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		self::assertSame($service, FleetAppId::getService($container, 'filinq', 'Service\PdfService'));
		self::assertSame(['OCA\Filinq\Service\PdfService', 'OCA\DocuDesk\Service\PdfService'], $asked);
	}//end testTheServiceIsFetchedUnderTheNamespaceTheContainerKnows()

	/**
	 * No service is answered when no candidate is registered, or for an unknown app.
	 *
	 * @return void
	 */
	public function testNoServiceWithoutARegisteredCandidate(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		self::assertNull(FleetAppId::getService($container, 'filinq', 'Service\PdfService'));

		$untouched = $this->createMock(ContainerInterface::class);
		$untouched->expects(self::never())->method('get');
		self::assertNull(FleetAppId::getService($untouched, 'openregister', 'Service\FileService'));
	}//end testNoServiceWithoutARegisteredCandidate()

	/**
	 * A class that exists under the newest namespace resolves to that name.
	 *
	 * @return void
	 */
	public function testAnExistingClassResolvesToItsName(): void {
		self::assertSame('OCA\Decidiq\Support\FleetAppId', FleetAppId::resolveClass('decidiq', '\Support\FleetAppId'));
	}//end testAnExistingClassResolvesToItsName()
}//end class
