<?php

/**
 * Unit tests for SigningController: Send for signature and collect the signed
 * copy on minutes, decision lists and motions.
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
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SigningController;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\SigningRoundService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SigningController.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SigningControllerTest extends TestCase {

	/**
	 * The signing round service double.
	 *
	 * @var SigningRoundService&MockObject
	 */
	private SigningRoundService $rounds;

	/**
	 * Build the controller.
	 *
	 * @param string|null $uid The logged-in user, or null for none
	 * @param bool $signatory Whether the guard lets the user sign
	 *
	 * @return SigningController
	 */
	private function makeController(?string $uid, bool $signatory): SigningController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$guard = $this->createMock(GovernanceScopeGuard::class);
		$guard->method('isSignatoryForSubject')->willReturnCallback(
			static fn (string $userId, string $schema, string $subjectId): bool => $signatory && $schema === 'decision' && $subjectId === 'dec-1'
		);

		$this->rounds = $this->createMock(SigningRoundService::class);

		return new SigningController($this->createMock(IRequest::class), $this->rounds, $session, $guard);
	}//end makeController()

	/**
	 * A signatory sends a motion for signature.
	 *
	 * @return void
	 */
	public function testASignatorySendsTheMotion(): void {
		$controller = $this->makeController(uid: 'griffier', signatory: true);
		$this->rounds->expects($this->once())->method('send')->with('motion', 'dec-1')
			->willReturn(['success' => true, 'message' => 'Sent for signature.', 'requestId' => 'req-7', 'signingUrl' => null]);

		$response = $controller->send(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertSame('req-7', $response->getData()['requestId']);
	}//end testASignatorySendsTheMotion()

	/**
	 * Someone outside the body's signatories cannot send, and nothing is sent.
	 *
	 * @return void
	 */
	public function testANonSignatoryIsRefusedAndNothingIsSent(): void {
		$controller = $this->makeController(uid: 'member', signatory: false);
		$this->rounds->expects($this->never())->method('send');

		$response = $controller->send(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testANonSignatoryIsRefusedAndNothingIsSent()

	/**
	 * A record type that cannot be signed is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownRecordTypeIsNotFound(): void {
		$controller = $this->makeController(uid: 'griffier', signatory: true);
		$this->rounds->expects($this->never())->method('send');

		$response = $controller->send(subjectType: 'agenda', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownRecordTypeIsNotFound()

	/**
	 * Without a login nothing happens.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorised(): void {
		$controller = $this->makeController(uid: null, signatory: true);
		$this->rounds->expects($this->never())->method('send');

		$response = $controller->send(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsUnauthorised()

	/**
	 * A refusal from the service reads as unprocessable, with its message.
	 *
	 * @return void
	 */
	public function testARefusedSendSaysWhy(): void {
		$controller = $this->makeController(uid: 'griffier', signatory: true);
		$this->rounds->method('send')->willReturn(['success' => false, 'message' => 'Add at least one signer first.']);

		$response = $controller->send(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Add at least one signer first.', $response->getData()['message']);
	}//end testARefusedSendSaysWhy()

	/**
	 * A signatory checks the round and gets the stored copy back.
	 *
	 * @return void
	 */
	public function testCollectReportsTheStoredCopy(): void {
		$controller = $this->makeController(uid: 'griffier', signatory: true);
		$this->rounds->expects($this->once())->method('collect')->with('motion', 'dec-1')
			->willReturn(['status' => 'signed', 'message' => 'The signed copy is stored.', 'signedCopy' => 'motie-getekend.pdf']);

		$response = $controller->collect(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('signed', $response->getData()['status']);
		self::assertSame('motie-getekend.pdf', $response->getData()['signedCopy']);
	}//end testCollectReportsTheStoredCopy()

	/**
	 * Collecting is guarded the same way.
	 *
	 * @return void
	 */
	public function testCollectIsGuarded(): void {
		$controller = $this->makeController(uid: 'member', signatory: false);
		$this->rounds->expects($this->never())->method('collect');

		$response = $controller->collect(subjectType: 'motion', subjectId: 'dec-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCollectIsGuarded()

	/**
	 * Every action is routed to a method that exists.
	 *
	 * @return void
	 */
	public function testTheRoutesReachTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');

		self::assertContains('signing#send', $names);
		self::assertContains('signing#collect', $names);
		self::assertTrue(method_exists(SigningController::class, 'send'));
		self::assertTrue(method_exists(SigningController::class, 'collect'));
	}//end testTheRoutesReachTheController()
}//end class
