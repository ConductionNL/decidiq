<?php

/**
 * Unit tests for EIDASSignatureController's unauthenticated and refusal paths.
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
 * @spec openspec/specs/signature-and-outcome-authorization/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\EIDASSignatureController;
use OCA\Decidiq\Controller\GovernanceControllerTrait;
use OCA\Decidiq\Kernel\Contracts\GovernanceScopeGuardInterface;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * verify(), finalize() and certStatus() without a session, and a refused finalisation.
 */
#[CoversClass(EIDASSignatureController::class)]
#[UsesClass(GovernanceControllerTrait::class)]
final class EIDASSignatureControllerRefusalTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock signature service.
	 *
	 * @var IEIDASSignatureService&MockObject
	 */
	private IEIDASSignatureService&MockObject $signatureService;

	/**
	 * Mock scope guard.
	 *
	 * @var GovernanceScopeGuardInterface&MockObject
	 */
	private GovernanceScopeGuardInterface&MockObject $scopeGuard;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request          = $this->createMock(IRequest::class);
		$this->signatureService = $this->createMock(IEIDASSignatureService::class);
		$this->scopeGuard       = $this->createMock(GovernanceScopeGuardInterface::class);

	}//end setUp()

	/**
	 * Build the controller for a caller, or for no session when $uid is null.
	 *
	 * @param string|null $uid The caller's UID.
	 *
	 * @return EIDASSignatureController
	 */
	private function controllerFor(?string $uid): EIDASSignatureController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new EIDASSignatureController(
			request: $this->request,
			signatureService: $this->signatureService,
			userSession: $session,
			scopeGuard: $this->scopeGuard,
		);

	}//end controllerFor()

	/**
	 * Without a session every read and write answers 401 and the signatory
	 * scope and the adapter are never consulted.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesAnUnauthenticatedCaller(): void {
		$this->scopeGuard->expects($this->never())->method('isSignatoryForMinutes');
		$this->signatureService->expects($this->never())->method('finalizeMinutes');
		$this->signatureService->expects($this->never())->method('validateCertificateChain');

		$controller = $this->controllerFor(null);

		foreach ([$controller->verify('min-1'), $controller->finalize('min-1'), $controller->certStatus()] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame(['message' => 'Authentication required.'], $response->getData());
		}

	}//end testEveryActionRefusesAnUnauthenticatedCaller()

	/**
	 * A finalisation the adapter refuses answers 422 with the adapter's message,
	 * after the signatures were passed on as given.
	 *
	 * @return void
	 */
	public function testARefusedFinalisationAnswersUnprocessable(): void {
		$signatures = [['signer' => 'chair', 'signature' => 'abc']];
		$this->request->method('getParam')->with('signatures', [])->willReturn($signatures);
		$this->scopeGuard->expects($this->once())
			->method('isSignatoryForMinutes')
			->with('chair', 'min-1')
			->willReturn(true);
		$this->signatureService->expects($this->once())
			->method('finalizeMinutes')
			->with('min-1', $signatures)
			->willReturn([
				'success' => false,
				'pdfArchiveReference' => null,
				'hashSha256' => null,
				'message' => 'Not every signatory has signed.',
			]);

		$response = $this->controllerFor('chair')->finalize('min-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'Not every signatory has signed.'], $response->getData());

	}//end testARefusedFinalisationAnswersUnprocessable()
}//end class
