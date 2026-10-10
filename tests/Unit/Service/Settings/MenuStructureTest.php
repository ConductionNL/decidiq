<?php

/**
 * Which structure an instance shows, and that both halves agree on the words.
 *
 * The default is the behaviour here: an instance that has never set the key is
 * every instance on the day this ships. So the tests ask what an unset key
 * reads as, and what the controller asks app config for, rather than only what
 * a stored value turns into.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Settings;

use OCA\Decidiq\Controller\DashboardController;
use OCA\Decidiq\Service\Settings\MenuStructure;
use OCP\AppFramework\Services\IInitialState;
use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the structure setting.
 */
final class MenuStructureTest extends TestCase {
	/**
	 * An unset key, an empty string and a typing mistake all read as simple.
	 *
	 * @return void
	 */
	public function testAnythingThatIsNotFullReadsAsSimple(): void {
		foreach (['', 'simple', 'Simple', 'ful', 'uitgebreid', 'yes', '1'] as $stored) {
			$this->assertSame(
				MenuStructure::SIMPLE,
				(new MenuStructure())->normalise($stored),
				sprintf("'%s' should read as the simple structure.", $stored),
			);
		}
	}//end testAnythingThatIsNotFullReadsAsSimple()

	/**
	 * The word full, however an operator types it into occ, brings the full one back.
	 *
	 * @return void
	 */
	public function testTheWordFullBringsTheFullStructureBack(): void {
		foreach (['full', 'FULL', ' full '] as $stored) {
			$this->assertSame(MenuStructure::FULL, (new MenuStructure())->normalise($stored));
		}
	}//end testTheWordFullBringsTheFullStructureBack()

	/**
	 * The page controller asks for its own key and decides the default itself.
	 *
	 * A mock of `getValueString()` answers whatever it is told to, so this
	 * records what the controller ASKED and hands the default back, the way an
	 * instance that never set the key does.
	 *
	 * @return void
	 */
	public function testAnInstanceThatNeverSetTheKeyShowsTheSimpleStructure(): void {
		$asked = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$asked): string {
				$asked = [$app, $key];

				return $default;
			}
		);

		$this->assertSame(MenuStructure::SIMPLE, $this->structureFrom(appConfig: $appConfig));
		$this->assertSame(['decidiq', MenuStructure::KEY], $asked);
	}//end testAnInstanceThatNeverSetTheKeyShowsTheSimpleStructure()

	/**
	 * A stored `full` reaches the page as `full`.
	 *
	 * @return void
	 */
	public function testAStoredFullReachesThePage(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('full');

		$this->assertSame(MenuStructure::FULL, $this->structureFrom(appConfig: $appConfig));
	}//end testAStoredFullReachesThePage()

	/**
	 * The settings write stores the key, and hands the stored word back.
	 *
	 * `SettingsService::updateSettings()` writes only the keys on its own list
	 * and answers success for the rest, so a key missing there is a save that
	 * reports done and changes nothing. The list is private, so this goes
	 * through the write itself: the fake app config keeps what it is given,
	 * and the test reads back what the admin tab reads back.
	 *
	 * @return void
	 */
	public function testTheSettingsWriteStoresTheKey(): void {
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$app . '/' . $key] = $value;

				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$app . '/' . $key] ?? $default);
			}
		);

		$service = new SettingsService(
			$appConfig,
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame('', $service->getSettings()[MenuStructure::KEY], 'An unset key reads as an empty string.');

		$config = $service->updateSettings([MenuStructure::KEY => MenuStructure::FULL]);

		$this->assertSame(MenuStructure::FULL, ($stored['decidiq/' . MenuStructure::KEY] ?? null));
		$this->assertSame(MenuStructure::FULL, $config[MenuStructure::KEY]);

		// The control: a key that is not on the list is dropped, which is the
		// failure the assertion above would otherwise not be able to see.
		$service->updateSettings(['no_such_setting' => 'x']);
		$this->assertArrayNotHasKey('decidiq/no_such_setting', $stored);
	}//end testTheSettingsWriteStoresTheKey()

	/**
	 * The PHP half and the JavaScript half use the same key and the same words.
	 *
	 * @return void
	 */
	public function testBothHalvesSpellTheSettingTheSameWay(): void {
		$source = file_get_contents(__DIR__ . '/../../../../src/utils/structureProfile.js');
		$this->assertIsString($source);

		foreach ([
			'STRUCTURE_SETTING' => MenuStructure::KEY,
			'STRUCTURE_SIMPLE' => MenuStructure::SIMPLE,
			'STRUCTURE_FULL' => MenuStructure::FULL,
		] as $constant => $expected) {
			$matched = preg_match('/export const ' . $constant . " = '([^']+)'/", $source, $matches);
			$this->assertSame(1, $matched, sprintf('structureProfile.js no longer declares %s.', $constant));
			$this->assertSame($expected, $matches[1], sprintf('%s differs between PHP and JavaScript.', $constant));
		}
	}//end testBothHalvesSpellTheSettingTheSameWay()

	/**
	 * Ask the page controller which structure it hands to the frontend.
	 *
	 * @param IAppConfig $appConfig The app config to read from.
	 *
	 * @return string The structure.
	 */
	private function structureFrom(IAppConfig $appConfig): string {
		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			$this->createMock(IInitialState::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$appConfig,
		);

		$method = new ReflectionMethod(DashboardController::class, 'menuStructure');
		$method->setAccessible(true);

		return (string)$method->invoke($controller);
	}//end structureFrom()
}//end class
