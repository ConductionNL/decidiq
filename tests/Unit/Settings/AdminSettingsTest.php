<?php

/**
 * The admin settings page: what it publishes and how it registers itself.
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
 * Tests for AdminSettings.
 *
 * @covers \OCA\Decidiq\Settings\AdminSettings
 * @uses \OCA\Decidiq\Service\Settings\MenuStructure
 */
final class AdminSettingsTest extends TestCase {
	/**
	 * Build the settings page over mocked boundaries, recording what it publishes.
	 *
	 * @param array<string,mixed> $published Filled with the initial state, by key.
	 * @param array<string,mixed> $publicationConfig What the publication service answers.
	 *
	 * @return AdminSettings The page.
	 */
	private function settings(array &$published = [], array $publicationConfig = []): AdminSettings {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->any())
			->method('getAppVersion')
			->with('decidiq')
			->willReturn('1.4.2');

		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$published): void {
				$published[$key] = $value;
			}
		);

		$publication = $this->createMock(PublicationConfigService::class);
		$publication->method('getAll')->willReturn($publicationConfig);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('full');

		return new AdminSettings($appManager, $initialState, $publication, $appConfig);
	}//end settings()

	/**
	 * The form publishes the version, the publication configuration as the
	 * service answers it, both policy enums and the retention defaults.
	 *
	 * @return void
	 */
	public function testTheFormPublishesItsInitialState(): void {
		$published = [];
		$config = ['body-1' => ['policy' => 'manual-only']];

		$response = $this->settings(published: $published, publicationConfig: $config)->getForm();

		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame([], $response->getParams());
		$this->assertSame('1.4.2', $published['version']);
		$this->assertSame($config, $published['publicationConfig']);
		$this->assertSame(
			[
				'policies' => PublicationConfigService::POLICIES,
				'attendance' => PublicationConfigService::ATTENDANCE_POLICIES,
			],
			$published['publicationPolicies']
		);
		$this->assertSame('delete-both', $published['transcriptRetentionDefaultPolicy']);
		$this->assertSame(30, $published['transcriptRetentionDefaultDays']);
		$this->assertSame(MenuStructure::FULL, $published[MenuStructure::KEY]);
	}//end testTheFormPublishesItsInitialState()

	/**
	 * The keys are published in a fixed order, with the audit last.
	 *
	 * @return void
	 */
	public function testTheKeysArePublishedInOrder(): void {
		$published = [];
		$this->settings(published: $published)->getForm();

		$this->assertSame(
			[
				'version',
				'publicationConfig',
				'publicationPolicies',
				'transcriptRetentionDefaultPolicy',
				'transcriptRetentionDefaultDays',
				MenuStructure::KEY,
				'accessibilityAudit',
			],
			array_keys($published)
		);
	}//end testTheKeysArePublishedInOrder()

	/**
	 * The page sits in the app's own section, first in it.
	 *
	 * @return void
	 */
	public function testSectionAndPriority(): void {
		$settings = $this->settings();

		$this->assertSame('decidiq', $settings->getSection());
		$this->assertSame(10, $settings->getPriority());
	}//end testSectionAndPriority()

	/**
	 * As a delegated setting it takes the section's own name and delegates no
	 * config keys, so only full admins pass the guard.
	 *
	 * @return void
	 */
	public function testDelegationExposesNoNameAndNoKeys(): void {
		$settings = $this->settings();

		$this->assertNull($settings->getName());
		$this->assertSame([], $settings->getAuthorizedAppConfig());
	}//end testDelegationExposesNoNameAndNoKeys()
}
