<?php

/**
 * SettingsController connection-registry tests.
 *
 * A settings save tells integriq two things: which connections to resolve
 * again, and which signing and translation services answer. Neither may change
 * what the save answers, and a controller built without the reporter must
 * still save.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-001-decidiq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SettingsController;
use OCA\Decidiq\Service\ConnectionReportService;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the connection reports a settings save sends.
 *
 * @covers \OCA\Decidiq\Controller\SettingsController
 */
class SettingsControllerConnectionReportTest extends TestCase {

	/**
	 * The payload an admin saves from the ORI endpoint section.
	 *
	 * @var array<string, string>
	 */
	private const ORI_SAVE = ['ori_endpoint' => 'https://ori.example.nl/v1/stemmingen'];

	/**
	 * Build the controller with a request carrying the given payload.
	 *
	 * @param ConnectionReportService|null $reporter The reporter, or none.
	 *
	 * @return SettingsController
	 */
	private function controller(?ConnectionReportService $reporter): SettingsController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(self::ORI_SAVE);

		$settingsService = $this->createMock(originalClassName: SettingsService::class);
		$settingsService->method('updateSettings')->willReturn(['ori_endpoint' => self::ORI_SAVE['ori_endpoint']]);

		return new SettingsController(
			request: $request,
			settingsService: $settingsService,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			publicationConfig: $this->createMock(originalClassName: PublicationConfigService::class),
			connectionReports: $reporter,
		);
	}//end controller()

	/**
	 * A save sends the saved payload for a refresh and reports the bindings.
	 *
	 * @return void
	 */
	public function testASaveRefreshesWithThePayloadAndReportsBindings(): void {
		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$reporter->expects($this->once())->method('refreshFromSave')->with(self::ORI_SAVE)->willReturn(['ori']);
		$reporter->expects($this->once())->method('reportBindings')->willReturn([]);

		$response = $this->controller(reporter: $reporter)->update();

		$this->assertSame(
			expected: ['success' => true, 'config' => ['ori_endpoint' => self::ORI_SAVE['ori_endpoint']]],
			actual: $response->getData()
		);
	}//end testASaveRefreshesWithThePayloadAndReportsBindings()

	/**
	 * The save answers the same whether or not anything was reported.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheReporterAnswersTheSame(): void {
		$withReporter = $this->controller(reporter: $this->createMock(originalClassName: ConnectionReportService::class))->update();
		$withoutReporter = $this->controller(reporter: null)->update();

		$this->assertSame(expected: $withReporter->getData(), actual: $withoutReporter->getData());
		$this->assertSame(expected: $withReporter->getStatus(), actual: $withoutReporter->getStatus());
	}//end testASaveWithoutTheReporterAnswersTheSame()
}//end class
