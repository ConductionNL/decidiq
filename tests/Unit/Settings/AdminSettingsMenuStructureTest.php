<?php

/**
 * The admin settings page is handed the structure the instance shows.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Settings
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

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\Settings\MenuStructure;
use OCA\Decidiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the structure choice on the admin settings page.
 */
final class AdminSettingsMenuStructureTest extends TestCase {
	/**
	 * Render the admin form with one stored value and return what it published.
	 *
	 * The fake app config answers the default it is asked for when nothing is
	 * stored, the way an instance that never set the key does.
	 *
	 * @param string|null $stored The stored structure, or null for an unset key.
	 *
	 * @return array<string,mixed> The published initial state, by key.
	 */
	private function publishedWith(?string $stored): array {
		$published = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$published): void {
				$published[$key] = $value;
			}
		);

		$asked = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($stored, &$asked): string {
				$asked[] = $app . '/' . $key;

				return ($stored ?? $default);
			}
		);

		$publication = $this->createMock(PublicationConfigService::class);
		$publication->method('getAll')->willReturn([]);

		$settings = new AdminSettings(
			$this->createMock(IAppManager::class),
			$initialState,
			$publication,
			$appConfig,
		);
		$response = $settings->getForm();

		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertContains('decidiq/' . MenuStructure::KEY, $asked);

		return $published;
	}//end publishedWith()

	/**
	 * An instance that never chose shows Simple as the chosen radio.
	 *
	 * @return void
	 */
	public function testAnUnsetKeyIsShownAsSimple(): void {
		$this->assertSame(MenuStructure::SIMPLE, $this->publishedWith(stored: null)[MenuStructure::KEY]);
	}//end testAnUnsetKeyIsShownAsSimple()

	/**
	 * A stored full is shown as Full.
	 *
	 * @return void
	 */
	public function testAStoredFullIsShownAsFull(): void {
		$this->assertSame(MenuStructure::FULL, $this->publishedWith(stored: 'full')[MenuStructure::KEY]);
	}//end testAStoredFullIsShownAsFull()

	/**
	 * A mistyped value is shown as the simple structure it behaves as.
	 *
	 * @return void
	 */
	public function testAMistypedValueIsShownAsSimple(): void {
		$this->assertSame(MenuStructure::SIMPLE, $this->publishedWith(stored: 'ful')[MenuStructure::KEY]);
	}//end testAMistypedValueIsShownAsSimple()

	/**
	 * The page still gets the keys it had before.
	 *
	 * @return void
	 */
	public function testTheOtherKeysAreStillPublished(): void {
		$published = $this->publishedWith(stored: null);
		foreach (['version', 'publicationConfig', 'publicationPolicies'] as $key) {
			$this->assertArrayHasKey($key, $published);
		}
	}//end testTheOtherKeysAreStillPublished()
}//end class
