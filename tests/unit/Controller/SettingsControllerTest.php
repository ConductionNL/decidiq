<?php

/**
 * Unit tests for SettingsController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SettingsController;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsController.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.4
 */
class SettingsControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock PublicationConfigService.
	 *
	 * @var PublicationConfigService&MockObject
	 */
	private PublicationConfigService&MockObject $publicationConfigService;

	/**
	 * Mock non-admin IUser.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $nonAdminUser;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->publicationConfigService = $this->createMock(originalClassName: PublicationConfigService::class);

		$this->nonAdminUser = $this->createMock(originalClassName: IUser::class);
		$this->nonAdminUser->method('getUID')->willReturn('regularuser');

		$this->controller = new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			publicationConfig: $this->publicationConfigService,
		);

	}//end setUp()

	/**
	 * Test that index() returns a JSONResponse containing the settings from the service.
	 *
	 * @return void
	 */
	public function testIndexReturnsJsonResponseWithSettings(): void {
		$settings = [
			'register' => 'some-uuid',
			'openregisters' => true,
			'isAdmin' => false,
		];

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($this->nonAdminUser);

		$this->settingsService->expects($this->once())
			->method('getSettings')
			->willReturn($settings);

		$result = $this->controller->index();

		self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
		self::assertSame(expected: $settings, actual: $result->getData());

	}//end testIndexReturnsJsonResponseWithSettings()

	/**
	 * Test that create() calls updateSettings with request params and returns success.
	 *
	 * Admin enforcement is handled by the #[AuthorizedAdminSetting] framework attribute,
	 * not by the controller itself.
	 *
	 * @return void
	 */
	public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void {
		$params = ['register' => 'new-uuid'];
		$updated = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => false];

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn($params);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($params)
			->willReturn($updated);

		$result = $this->controller->create();

		self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
		self::assertTrue(condition: $result->getData()['success']);
		self::assertArrayHasKey(key: 'config', array: $result->getData());

	}//end testCreateCallsUpdateSettingsAndReturnsSuccess()

	/**
	 * Test that load() returns the result of reloadConfiguration.
	 *
	 * load() is the forcing endpoint, so it must call the forcing named method
	 * (reloadConfiguration) rather than the non-forcing loadConfiguration().
	 *
	 * Admin enforcement is handled by the #[AuthorizedAdminSetting] framework attribute,
	 * not by the controller itself.
	 *
	 * @return void
	 */
	public function testLoadReturnsConfigurationResult(): void {
		$loadResult = [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'version' => '0.1.0',
		];

		$this->settingsService->expects($this->never())
			->method('loadConfiguration');

		$this->settingsService->expects($this->once())
			->method('reloadConfiguration')
			->willReturn($loadResult);

		$result = $this->controller->load();

		self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
		self::assertTrue(condition: $result->getData()['success']);

	}//end testLoadReturnsConfigurationResult()

}//end class
