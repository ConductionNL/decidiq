<?php

/**
 * Unit tests for SettingsController's reads and the publication configuration.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SettingsController;
use OCA\Decidiq\Kernel\Contracts\PublicationConfigServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsController reads.
 *
 * @covers \OCA\Decidiq\Controller\SettingsController
 */
class SettingsControllerReadTest extends TestCase {

	/**
	 * The settings service double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * The publication config double.
	 *
	 * @var PublicationConfigServiceInterface&MockObject
	 */
	private PublicationConfigServiceInterface&MockObject $publication;

	/**
	 * A controller for this caller and this `config` request parameter.
	 *
	 * @param bool  $signedIn Whether a user is signed in.
	 * @param mixed $config   The `config` request parameter.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function controller(bool $signedIn = true, mixed $config = []): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'config' ? $config : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->publication = $this->createMock(PublicationConfigServiceInterface::class);

		return new SettingsController(
			request: $request,
			settingsService: $this->settings,
			userSession: $session,
			publicationConfig: $this->publication,
		);
	}//end controller()

	/**
	 * A signed-in user reads the settings.
	 *
	 * @return void
	 */
	public function testASignedInUserReadsTheSettings(): void {
		$controller = $this->controller();
		$this->settings->method('getSettings')->willReturn(['register' => 'decidiq']);

		$response = $controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['register' => 'decidiq'], $response->getData());
	}//end testASignedInUserReadsTheSettings()

	/**
	 * Without a user neither the settings nor the publication configuration are read.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		$controller = $this->controller(signedIn: false);
		$this->settings->expects(self::never())->method('getSettings');
		$this->publication->expects(self::never())->method('getAll');

		foreach ([$controller->index(), $controller->getPublicationConfig()] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame(['message' => 'Authentication required'], $response->getData());
		}
	}//end testWithoutAUserNothingIsRead()

	/**
	 * A signed-in user reads the publication configuration under `config`.
	 *
	 * @return void
	 */
	public function testThePublicationConfigIsRead(): void {
		$controller = $this->controller();
		$this->publication->method('getAll')->willReturn(['raad' => ['publish' => true]]);

		self::assertSame(['config' => ['raad' => ['publish' => true]]], $controller->getPublicationConfig()->getData());
	}//end testThePublicationConfigIsRead()

	/**
	 * The posted configuration is saved and the normalised result returned.
	 *
	 * @return void
	 */
	public function testThePublicationConfigIsSaved(): void {
		$controller = $this->controller(config: ['raad' => ['publish' => '1']]);
		$this->publication->expects(self::once())->method('save')->with(['raad' => ['publish' => '1']])
			->willReturn(['raad' => ['publish' => true]]);

		self::assertSame(['success' => true, 'config' => ['raad' => ['publish' => true]]], $controller->setPublicationConfig()->getData());
	}//end testThePublicationConfigIsSaved()

	/**
	 * A posted configuration that is not an object saves an empty one.
	 *
	 * @return void
	 */
	public function testANonArrayConfigSavesEmpty(): void {
		$controller = $this->controller(config: 'not-a-map');
		$this->publication->expects(self::once())->method('save')->with([])->willReturn([]);

		self::assertSame(['success' => true, 'config' => []], $controller->setPublicationConfig()->getData());
	}//end testANonArrayConfigSavesEmpty()
}//end class
