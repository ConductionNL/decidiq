<?php

/**
 * Rights per record type endpoint (platform-role-rights-per-record-type, plt-03).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\RoleRightsController;
use OCA\Decidiq\Service\RoleGroupMapping;
use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The page reads the rules of the real merged register and stores a mapping
 * only for groups that exist, then re-imports.
 *
 * @covers \OCA\Decidiq\Controller\RoleRightsController
 * @uses   \OCA\Decidiq\Service\RoleGroupMapping
 * @uses   \OCA\Decidiq\Service\SettingsService
 */
class RoleRightsControllerTest extends TestCase {

	/** @var array<string,string> */
	private array $config = [];

	/** @var list<string> */
	private array $imported = [];

	/**
	 * A controller over a real SettingsService whose import records its version.
	 *
	 * @param bool $importWorks Whether OpenRegister accepts the import.
	 *
	 * @return RoleRightsController
	 */
	private function controller(bool $importWorks=true): RoleRightsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$test     = $this;
		$importer = new class ($test, $importWorks) {
			/**
			 * Constructor.
			 *
			 * @param RoleRightsControllerTest $test  The test, to record imports.
			 * @param bool                     $works Whether the import succeeds.
			 */
			public function __construct(private RoleRightsControllerTest $test, private bool $works) {
			}

			/**
			 * OpenRegister's ConfigurationService::importFromApp.
			 *
			 * @param string              $appId   The app.
			 * @param array<string,mixed> $data    The register.
			 * @param string              $version The version.
			 * @param bool                $force   Force.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force=false): array {
				$this->test->recordImport(version: $version);
				return $this->works === true ? ['version' => $version] : [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'Griffie');
		$griffie = $this->createMock(IGroup::class);
		$griffie->method('getGID')->willReturn('Griffie');
		$groupManager->method('search')->willReturn([$griffie]);

		$settings = new SettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			groupManager: $groupManager,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return new RoleRightsController(
			request: $this->createMock(IRequest::class),
			settings: $settings,
			mapping: new RoleGroupMapping(),
			appConfig: $appConfig,
			groupManager: $groupManager,
		);
	}//end controller()

	/**
	 * Called by the fake importer.
	 *
	 * @param string $version The imported version.
	 *
	 * @return void
	 */
	public function recordImport(string $version): void {
		$this->imported[] = $version;
	}//end recordImport()

	public function testTheAdministratorReadsWhoMayChangeMinutes(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(['administrators', 'secretariat', 'publication-flow'], array_column($data['roles'], 'role'));
		$this->assertSame(['Griffie'], $data['groups']);
		$rows = array_column($data['recordTypes'], null, 'slug');
		$this->assertArrayHasKey('minutes', $rows);
		$this->assertContains('decidiq-administrators', array_column($rows['minutes']['rules']['update'], 'group'));
	}//end testTheAdministratorReadsWhoMayChangeMinutes()

	public function testMappingGriffieReimportsAndTheMinutesRowNamesIt(): void {
		$response = $this->controller()->update(mapping: ['administrators' => ['Griffie']]);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('{"administrators":["Griffie"],"secretariat":[],"publication-flow":[]}', $this->config[RoleGroupMapping::CONFIG_KEY]);
		$this->assertCount(1, $this->imported);
		$this->assertStringContainsString('+roles.', $this->imported[0]);
		$rows = array_column($response->getData()['recordTypes'], null, 'slug');
		$this->assertContains('Griffie', array_column($rows['minutes']['rules']['update'], 'group'));
	}//end testMappingGriffieReimportsAndTheMinutesRowNamesIt()

	public function testAGroupThatDoesNotExistIsRefusedAndNothingIsStored(): void {
		$response = $this->controller()->update(mapping: ['administrators' => ['Griffie', 'Nobody-here']]);

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Nobody-here', $response->getData()['message']);
		$this->assertArrayNotHasKey(RoleGroupMapping::CONFIG_KEY, $this->config);
		$this->assertSame([], $this->imported);
	}//end testAGroupThatDoesNotExistIsRefusedAndNothingIsStored()

	public function testAFailedReimportSaysTheRulesStillNameTheOldGroups(): void {
		$response = $this->controller(importWorks: false)->update(mapping: ['administrators' => ['Griffie']]);

		$this->assertSame(503, $response->getStatus());
		$this->assertStringContainsString('still name the old groups', $response->getData()['message']);
	}//end testAFailedReimportSaysTheRulesStillNameTheOldGroups()
}//end class
