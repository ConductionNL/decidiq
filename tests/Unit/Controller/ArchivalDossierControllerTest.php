<?php

/**
 * Unit tests for ArchivalDossierController: each refusal of the dossier
 * service answers its own status.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ArchivalDossierController;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\ArchivalDossierService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Status mapping of the dossier actions.
 *
 * @covers \OCA\Decidiq\Controller\ArchivalDossierController
 * @uses   \OCA\Decidiq\Exception\DossierRefusedException
 */
class ArchivalDossierControllerTest extends TestCase {

	/**
	 * A controller over a service double.
	 *
	 * @param ArchivalDossierService $service  The service double
	 * @param bool                   $signedIn Whether someone is signed in
	 *
	 * @return ArchivalDossierController
	 */
	private function controller(ArchivalDossierService $service, bool $signedIn = true): ArchivalDossierController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ArchivalDossierController($this->createMock(IRequest::class), $service, $session, new NullLogger());
	}//end controller()

	/**
	 * Forming answers 201 with the dossier; gathering and closing 200.
	 *
	 * @return void
	 */
	public function testSuccessAnswersTheDossier(): void {
		$service = $this->createMock(ArchivalDossierService::class);
		$service->method('formForMeeting')->with('m-1')->willReturn(['id' => 'd-1', 'lifecycle' => 'forming']);
		$service->method('assemble')->with('d-1')->willReturn(['id' => 'd-1', 'lifecycle' => 'forming']);
		$service->method('close')->with('d-1', 'reden')->willReturn(['id' => 'd-1', 'lifecycle' => 'closed']);
		$controller = $this->controller(service: $service);

		$formed = $controller->form(meetingId: 'm-1');
		self::assertSame(201, $formed->getStatus());
		self::assertSame('d-1', $formed->getData()['dossier']['id']);
		self::assertSame(200, $controller->assemble(id: 'd-1')->getStatus());
		self::assertSame('closed', $controller->close(id: 'd-1', overrideReason: 'reden')->getData()['dossier']['lifecycle']);
	}//end testSuccessAnswersTheDossier()

	/**
	 * Each refusal answers its status: no authority 403, unknown 404, frozen
	 * 409, gaps 422 with the gaps, anything else 500; signed out 401.
	 *
	 * @return void
	 */
	public function testRefusalsAnswerTheirStatus(): void {
		$cases = [
			[new AccessDeniedException('no'), 403],
			[new MissingObjectException('gone'), 404],
			[new DossierRefusedException('frozen', DossierRefusedException::FROZEN), 409],
			[new DossierRefusedException('gaps', DossierRefusedException::GAPS, ['minutes-not-approved']), 422],
			[new RuntimeException('database down'), 500],
		];
		foreach ($cases as [$exception, $status]) {
			$service = $this->createMock(ArchivalDossierService::class);
			$service->method('close')->willThrowException($exception);
			$response = $this->controller(service: $service)->close(id: 'd-1');
			self::assertSame($status, $response->getStatus(), get_class($exception));
			if ($status === 422) {
				self::assertSame(['minutes-not-approved'], $response->getData()['gaps']);
			}

			if ($status === 500) {
				self::assertStringNotContainsString('database', $response->getData()['message']);
			}
		}

		$service = $this->createMock(ArchivalDossierService::class);
		$service->expects(self::never())->method('formForMeeting');
		self::assertSame(401, $this->controller(service: $service, signedIn: false)->form(meetingId: 'm-1')->getStatus());
	}//end testRefusalsAnswerTheirStatus()
}//end class
