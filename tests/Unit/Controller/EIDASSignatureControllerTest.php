<?php

/**
 * Unit tests for EIDASSignatureController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\EIDASSignatureController;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EIDASSignatureController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class EIDASSignatureControllerTest extends TestCase {

	/**
	 * Build a controller wired to the supplied adapter and request params.
	 *
	 * @param IEIDASSignatureService $service The eIDAS adapter double
	 * @param array<string, mixed> $requestParams Params returned by IRequest
	 * @param bool $authenticated Whether the session has a user
	 * @param bool $authorised Whether the OR-projected signatory scope allows the caller (R-4)
	 *
	 * @return EIDASSignatureController
	 */
	private function makeController(
		IEIDASSignatureService $service,
		array $requestParams = [],
		bool $authenticated = true,
		bool $authorised = true,
	): EIDASSignatureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($requestParams);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($requestParams): mixed {
				return ($requestParams[$key] ?? $default);
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$scopeGuard = $this->createMock(GovernanceScopeGuard::class);
		$scopeGuard->method('canInitiateSigning')->willReturn($authorised);
		// `verify()` and `finalize()` consult the same signatory determination
		// that `initiate()` does — one scope, three endpoints of one flow.
		$scopeGuard->method('isSignatoryForMinutes')->willReturn($authorised);

		return new EIDASSignatureController($request, $service, $session, $scopeGuard);
	}//end makeController()

	/**
	 * initiate returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testInitiateRequiresAuthentication(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$controller = $this->makeController($service, authenticated: false);

		$response = $controller->initiate('min-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testInitiateRequiresAuthentication()

	/**
	 * R-4: initiate returns 403 when the caller is authenticated but is NOT a
	 * chair, vice-chair, or secretary on the GovernanceBody linked to the
	 * Minutes. Without this guard any authed user could spam any Minutes UUID
	 * with a QES signing flow.
	 *
	 * @return void
	 */
	public function testInitiateReturnsForbiddenWhenNotASignatory(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		// Service must NEVER be invoked when the guard denies — otherwise the
		// notification-spam vector is still open.
		$service->expects($this->never())->method('initializeSigningRequest');

		$controller = $this->makeController(
			$service,
			requestParams: ['signatories' => ['m-1']],
			authorised: false,
		);

		$response = $controller->initiate('min-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('not authorised', (string)$response->getData()['message']);

	}//end testInitiateReturnsForbiddenWhenNotASignatory()

	/**
	 * initiate returns 202 + payload on success.
	 *
	 * @return void
	 */
	public function testInitiateReturnsAcceptedOnSuccess(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->expects($this->once())->method('initializeSigningRequest')
			->willReturn(
				[
					'success' => true,
					'requestId' => 'req-1',
					'signingUrl' => 'https://qsp/sign/1',
					'message' => 'Signing request initiated.',
				]
			);

		$controller = $this->makeController($service, requestParams: ['signatories' => ['m-1']]);

		$response = $controller->initiate('min-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('req-1', $data['requestId']);
		$this->assertSame('https://qsp/sign/1', $data['signingUrl']);

	}//end testInitiateReturnsAcceptedOnSuccess()

	/**
	 * initiate returns 422 on service failure.
	 *
	 * @return void
	 */
	public function testInitiateReturns422OnServiceFailure(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->method('initializeSigningRequest')->willReturn(
			[
				'success' => false,
				'requestId' => null,
				'signingUrl' => null,
				'message' => 'eIDAS QES integration is not configured.',
			]
		);

		$controller = $this->makeController($service, requestParams: ['signatories' => ['m-1']]);

		$response = $controller->initiate('min-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('eIDAS QES integration is not configured.', $response->getData()['message']);

	}//end testInitiateReturns422OnServiceFailure()

	/**
	 * verify rejects missing parameters.
	 *
	 * @return void
	 */
	public function testVerifyRejectsMissingParameters(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$controller = $this->makeController($service, requestParams: ['requestId' => 'req-1']);

		$response = $controller->verify('min-1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testVerifyRejectsMissingParameters()

	/**
	 * verify surfaces the service verdict with the minutesId included.
	 *
	 * @return void
	 */
	public function testVerifyReturnsServiceVerdict(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->method('verifySignature')->willReturn(
			[
				'valid' => true,
				'certificateThumbprint' => 'thumb-aabb',
				'timestamp' => '2026-06-10T12:00:00Z',
				'message' => 'Signature verified.',
			]
		);

		$controller = $this->makeController(
			$service,
			requestParams: ['requestId' => 'req-1', 'signature' => 'sig-blob']
		);

		$response = $controller->verify('min-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['valid']);
		$this->assertSame('min-1', $data['minutesId']);

	}//end testVerifyReturnsServiceVerdict()

	/**
	 * REQ-SIG-102 (deny): verify returns 403 when the caller holds no signatory
	 * role on the GovernanceBody owning these minutes, and the adapter is never
	 * reached — the response would otherwise hand out the signer's eIDAS
	 * certificate thumbprint, which identifies a natural person.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-102-only-a-body-signatory-may-verify-a-signature-on-a-minutes-record
	 */
	public function testVerifyReturnsForbiddenWhenNotASignatory(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->expects($this->never())->method('verifySignature');

		$controller = $this->makeController(
			$service,
			requestParams: ['requestId' => 'req-1', 'signature' => 'sig-blob'],
			authorised: false,
		);

		$response = $controller->verify('min-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('not authorised', (string)$response->getData()['message']);

	}//end testVerifyReturnsForbiddenWhenNotASignatory()

	/**
	 * REQ-SIG-101 (deny): finalize returns 403 when the caller holds no
	 * signatory role on the GovernanceBody owning these minutes, and
	 * `finalizeMinutes()` is NEVER invoked.
	 *
	 * This is the endpoint that affixes the signature: reaching the service
	 * would write `version = signed`, `eidasSignatureLevel = QES`, the archive
	 * reference and the hash onto the Minutes row, resolve the signature stage
	 * to `outcome=adopted`, and append a `signature` audit entry. Asserting the
	 * 403 alone would not prove the write did not happen — the `never()`
	 * expectation is the part that does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes
	 */
	public function testFinalizeReturnsForbiddenWhenNotASignatory(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->expects($this->never())->method('finalizeMinutes');

		$controller = $this->makeController(
			$service,
			requestParams: ['signatures' => [['signer' => 'm-1', 'signature' => 's', 'timestamp' => 't']]],
			authorised: false,
		);

		$response = $controller->finalize('min-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('not authorised', (string)$response->getData()['message']);

	}//end testFinalizeReturnsForbiddenWhenNotASignatory()

	/**
	 * REQ-SIG-101/102 (allow): the guard is consulted with the Minutes UUID
	 * from the route, and a caller inside the body's signatory scope still gets
	 * through on both endpoints.
	 *
	 * A guard proven only in the deny direction is not evidence — this pins the
	 * allow direction AND the argument the guard is asked about, so the check
	 * cannot silently be made against the wrong object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes
	 */
	public function testSignatoryIsAllowedAndTheGuardIsAskedAboutTheRoutedMinutes(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$params = ['signatures' => [['signer' => 'm-1', 'signature' => 's', 'timestamp' => 't']]];
				return ($params[$key] ?? $default);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$scopeGuard = $this->createMock(GovernanceScopeGuard::class);
		$scopeGuard->expects($this->once())
			->method('isSignatoryForMinutes')
			->with('alice', 'min-42')
			->willReturn(true);

		$service = $this->createMock(IEIDASSignatureService::class);
		$service->expects($this->once())->method('finalizeMinutes')->willReturn(
			[
				'success' => true,
				'pdfArchiveReference' => 'docudesk/min/42.pdf',
				'hashSha256' => 'bb',
				'message' => 'Minutes finalized.',
			]
		);

		$controller = new EIDASSignatureController($request, $service, $session, $scopeGuard);

		$response = $controller->finalize('min-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('docudesk/min/42.pdf', $response->getData()['pdfArchiveReference']);

	}//end testSignatoryIsAllowedAndTheGuardIsAskedAboutTheRoutedMinutes()

	/**
	 * finalize returns 422 on failure, 200 on success.
	 *
	 * @return void
	 */
	public function testFinalizeReturnsServiceShape(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->method('finalizeMinutes')->willReturn(
			[
				'success' => true,
				'pdfArchiveReference' => 'docudesk/min/1.pdf',
				'hashSha256' => 'aa',
				'message' => 'Minutes finalized.',
			]
		);

		$controller = $this->makeController(
			$service,
			requestParams: ['signatures' => [['signer' => 'm-1', 'signature' => 's', 'timestamp' => 't']]]
		);

		$response = $controller->finalize('min-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('docudesk/min/1.pdf', $data['pdfArchiveReference']);
		$this->assertSame('aa', $data['hashSha256']);

	}//end testFinalizeReturnsServiceShape()

	/**
	 * certStatus rejects an empty thumbprint.
	 *
	 * @return void
	 */
	public function testCertStatusRejectsEmptyThumbprint(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$controller = $this->makeController($service, requestParams: []);

		$response = $controller->certStatus();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testCertStatusRejectsEmptyThumbprint()

	/**
	 * certStatus returns the service verdict on success.
	 *
	 * @return void
	 */
	public function testCertStatusReturnsServiceVerdict(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->method('validateCertificateChain')->willReturn(
			[
				'valid' => true,
				'issuer' => 'CN=Example',
				'trustListLevel' => 'qualified',
				'message' => 'Certificate chain valid.',
			]
		);

		$controller = $this->makeController($service, requestParams: ['certificateThumbprint' => 'thumb']);

		$response = $controller->certStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['valid']);
		$this->assertSame('CN=Example', $data['issuer']);

	}//end testCertStatusReturnsServiceVerdict()

	/**
	 * REQ-SIG-103: certStatus is deliberately app-wide. A caller who holds NO
	 * signatory scope anywhere still gets the trust-list verdict, because the
	 * endpoint takes no caller-supplied object identifier — its only input is a
	 * certificate thumbprint and its only output comes from the public EU
	 * Trusted List.
	 *
	 * This test exists so the posture reads as a decision rather than an
	 * omission: if someone later narrows certStatus to the signatory scope, this
	 * fails and the ADR-044 functionality question gets asked deliberately.
	 * Its counterpart — the 401 for an unauthenticated caller through the real
	 * middleware chain — is folder 2 of
	 * tests/integration/decidiq-security-flow-e2e.postman_collection.json.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-103-certificate-trust-status-lookup-is-a-deliberately-app-wide-authenticated-read
	 */
	public function testCertStatusStaysOpenToAnyAuthenticatedCaller(): void {
		$service = $this->createMock(IEIDASSignatureService::class);
		$service->expects($this->once())->method('validateCertificateChain')->willReturn(
			[
				'valid' => false,
				'issuer' => null,
				'trustListLevel' => null,
				'message' => 'Certificate not on EU Trusted List.',
			]
		);

		$controller = $this->makeController(
			$service,
			requestParams: ['certificateThumbprint' => 'thumb'],
			authorised: false,
		);

		$response = $controller->certStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['valid']);

	}//end testCertStatusStaysOpenToAnyAuthenticatedCaller()

}//end class
