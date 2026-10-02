<?php

/**
 * Unit tests for DossierDispositionController: each refusal of the routing
 * answers its own status.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DossierDispositionController;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\Records\DestructionCertificateRenderer;
use OCA\Decidiq\Service\Records\DossierDisposition;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Status mapping of the disposition actions.
 *
 * @covers \OCA\Decidiq\Controller\DossierDispositionController
 * @uses   \OCA\Decidiq\Exception\DossierRefusedException
 */
class DossierDispositionControllerTest extends TestCase {

	/**
	 * A controller over a routing double.
	 *
	 * @param DossierDisposition $disposition The routing double
	 * @param bool               $signedIn    Whether someone is signed in
	 *
	 * @return DossierDispositionController
	 */
	private function controller(DossierDisposition $disposition, bool $signedIn = true, ?DestructionCertificateRenderer $certificates = null): DossierDispositionController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new DossierDispositionController($this->createMock(IRequest::class), $disposition, ($certificates ?? $this->createMock(DestructionCertificateRenderer::class)), $session, new NullLogger());
	}//end controller()

	/**
	 * Describing and reading the outcome answer 200, proposing 201.
	 *
	 * @return void
	 */
	public function testSuccessAnswersTheDossier(): void {
		$disposition = $this->createMock(DossierDisposition::class);
		$disposition->method('describe')->with('d-1')->willReturn(['id' => 'd-1', 'route' => 'transfer']);
		$disposition->method('propose')->with('d-1')->willReturn(['id' => 'd-1', 'transferList' => 't-1']);
		$disposition->method('reflectOutcome')->with('d-1')->willReturn(['id' => 'd-1', 'lifecycle' => 'transferred']);
		$controller = $this->controller(disposition: $disposition);

		self::assertSame('transfer', $controller->show(id: 'd-1')->getData()['dossier']['route']);
		$proposed = $controller->propose(id: 'd-1');
		self::assertSame(201, $proposed->getStatus());
		self::assertSame('t-1', $proposed->getData()['dossier']['transferList']);
		self::assertSame('transferred', $controller->outcome(id: 'd-1')->getData()['dossier']['lifecycle']);

		$certificates = $this->createMock(DestructionCertificateRenderer::class);
		$certificates->method('render')->with('d-1')->willReturn(['id' => 'd-1', 'destructionCertificate' => ['format' => 'markdown']]);
		$rendered = $this->controller(disposition: $disposition, certificates: $certificates)->certificate(id: 'd-1');
		self::assertSame(201, $rendered->getStatus());
		self::assertSame('markdown', $rendered->getData()['dossier']['destructionCertificate']['format']);
	}//end testSuccessAnswersTheDossier()

	/**
	 * No authority 403, unknown 404, a conflict with state 409, nothing
	 * eligible 422 with OpenRegister's refusals, anything else 500; signed
	 * out 401.
	 *
	 * @return void
	 */
	public function testRefusalsAnswerTheirStatus(): void {
		$cases = [
			[new AccessDeniedException('no'), 403],
			[new MissingObjectException('gone'), 404],
			[new DossierRefusedException('closed?', DossierRefusedException::NOT_CLOSED), 409],
			[new DossierRefusedException('no e-depot', DossierRefusedException::TRANSFER_UNAVAILABLE), 409],
			[new DossierRefusedException('no register', DossierRefusedException::DESTRUCTION_UNAVAILABLE), 409],
			[new DossierRefusedException('twice', DossierRefusedException::ALREADY_PROPOSED), 409],
			[new DossierRefusedException('none', DossierRefusedException::NOTHING_ELIGIBLE, ['u-1: legal_hold']), 422],
			[new DossierRefusedException('category?', DossierRefusedException::NO_CATEGORY), 422],
			[new DossierRefusedException('no certificate', DossierRefusedException::CERTIFICATE_MISSING), 409],
			[new RuntimeException('database down'), 500],
		];
		foreach ($cases as [$exception, $status]) {
			$disposition = $this->createMock(DossierDisposition::class);
			$disposition->method('propose')->willThrowException($exception);
			$response = $this->controller(disposition: $disposition)->propose(id: 'd-1');
			self::assertSame($status, $response->getStatus(), $exception->getMessage());
			if ($exception instanceof DossierRefusedException && $exception->getReason() === DossierRefusedException::NOTHING_ELIGIBLE) {
				self::assertSame(['u-1: legal_hold'], $response->getData()['refused']);
			}

			if ($status === 500) {
				self::assertStringNotContainsString('database', $response->getData()['message']);
			}
		}

		$disposition = $this->createMock(DossierDisposition::class);
		$disposition->expects(self::never())->method('describe');
		self::assertSame(401, $this->controller(disposition: $disposition, signedIn: false)->show(id: 'd-1')->getStatus());
	}//end testRefusalsAnswerTheirStatus()
}//end class
