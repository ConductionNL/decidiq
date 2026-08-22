<?php

/**
 * SettingsController write-path unit tests.
 *
 * Covers the canonical `PUT /api/settings` write (`settings#update`), the
 * legacy `POST /api/settings` alias (`settings#create`) that delegates to it,
 * and the auth posture both must carry.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-organization-configuration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SettingsController;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The canonical AppHost route table routes BOTH `PUT /api/settings`
 * (`settings#update`) and `POST /api/settings` (`settings#create`) into this
 * controller, and because decidiq ships the class itself no generic is
 * aliased in to cover either.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters, and
 * that the returned payload carries the service's refreshed result. A test
 * that only checked for a 200, or only that the response was a JSONResponse,
 * would pass against a controller that silently wrote nothing.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-organization-configuration
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-boilerplate-delegation
 */
class SettingsControllerWriteTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * Build the controller under test with the collaborators mocked.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function controller(): SettingsController {
		return new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $this->createMock(IUserSession::class),
			publicationConfig: $this->createMock(PublicationConfigService::class)
		);
	}//end controller()

	/**
	 * PUT /api/settings must persist the request parameters and return the
	 * refreshed settings map the service actually stored.
	 *
	 * @return void
	 */
	public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void {
		$submitted = ['organisation_name' => 'Vereniging De Harmonie'];
		$stored = [
			'organisation_name' => 'Vereniging De Harmonie',
			'organisation_timezone' => 'Europe/Amsterdam',
			'organisatie_modus' => 'gov',
		];

		$this->request->method('getParams')->willReturn($submitted);

		// The ITEM: the write reaches the service, with the submitted params.
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->update();

		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'update() must return the refreshed config the service stored, not the submission'
		);
	}//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

	/**
	 * POST /api/settings is the legacy alias and must write identically.
	 *
	 * `src/store/modules/settings.js::saveSettings()` still POSTs here and
	 * unwraps the `{success, config}` envelope, so the alias staying a real
	 * write — not an empty success — is load-bearing.
	 *
	 * @return void
	 */
	public function testCreateDelegatesToUpdateAndStillWrites(): void {
		$submitted = ['email_voting_enabled' => 'true'];
		$stored = ['email_voting_enabled' => 'true'];

		$this->request->method('getParams')->willReturn($submitted);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->create();

		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'create() must produce the same written result as update()'
		);
	}//end testCreateDelegatesToUpdateAndStillWrites()

	/**
	 * The write must not be skipped when the submission is empty.
	 *
	 * An early return on an empty payload would look identical to a
	 * successful no-op write from the caller's side.
	 *
	 * @return void
	 */
	public function testEmptySubmissionStillReachesTheService(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with([])
			->willReturn(['unchanged' => true]);

		$response = $this->controller()->update();

		$this->assertSame(
			[
				'success' => true,
				'config' => ['unchanged' => true],
			],
			$response->getData()
		);
	}//end testEmptySubmissionStillReachesTheService()

	/**
	 * Both write methods must carry the admin posture.
	 *
	 * Nextcloud's middleware evaluates auth attributes on the DISPATCHED
	 * method only, so `create()` delegating to `update()` does NOT inherit
	 * `update()`'s posture — each needs its own attribute. And because
	 * `SettingsService::updateSettings()` writes instance-wide `IAppConfig`,
	 * neither may carry the `#[NoAdminRequired]` posture of the read routes
	 * (`index()`, `getPublicationConfig()`).
	 *
	 * @return void
	 */
	public function testBothWriteMethodsRequireAdmin(): void {
		$reflection = new ReflectionClass(SettingsController::class);
		$checked = 0;

		foreach (['update', 'create'] as $methodName) {
			$method = $reflection->getMethod($methodName);

			$attributeNames = array_map(
				static fn ($attribute) => $attribute->getName(),
				$method->getAttributes()
			);

			$this->assertContains(
				AuthorizedAdminSetting::class,
				$attributeNames,
				sprintf(
					'SettingsController::%s() writes instance-wide app config and MUST carry '
					. '#[AuthorizedAdminSetting]. The middleware only reads the dispatched '
					. 'method, so delegation does not inherit the posture.',
					$methodName
				)
			);

			$this->assertNotContains(
				'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
				$attributeNames,
				sprintf(
					'SettingsController::%s() is a write; #[NoAdminRequired] here would open '
					. 'instance-wide app config to any authenticated user.',
					$methodName
				)
			);

			$checked++;
		}//end foreach

		// Positive control: the loop must have actually reflected something.
		$this->assertSame(2, $checked, 'Both write methods must have been inspected');
	}//end testBothWriteMethodsRequireAdmin()

}//end class
