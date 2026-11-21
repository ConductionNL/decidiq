<?php

/**
 * The impose endpoint of a confidentiality restriction.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ConfidentialityRestrictionController;
use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for ConfidentialityRestrictionController.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityRestrictionControllerTest extends TestCase {

	/**
	 * The request body.
	 *
	 * @var array<string, mixed>
	 */
	private const BODY = ['scope' => 'item', 'target' => 'item-1', 'ground' => 'ground-gw', 'imposedBy' => 'body', 'imposedByBody' => 'body-raad', 'extra' => 'ignored'];

	/**
	 * The controller with a caller and an imposer.
	 *
	 * @param string|null            $uid     The caller, or null when signed out.
	 * @param ConfidentialityImposer $imposer The imposer.
	 *
	 * @return ConfidentialityRestrictionController
	 */
	private function controller(?string $uid, ConfidentialityImposer $imposer): ConfidentialityRestrictionController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => (self::BODY[$key] ?? $default));
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ConfidentialityRestrictionController(request: $request, userSession: $session, imposer: $imposer);
	}//end controller()

	/**
	 * Without a user: 401, and the imposer is not called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testSignedOutIsUnauthorised(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->expects($this->never())->method('impose');

		$this->assertSame(401, $this->controller(uid: null, imposer: $imposer)->impose()->getStatus());
	}//end testSignedOutIsUnauthorised()

	/**
	 * The five fields reach the imposer for the caller, and the answer is 201.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testImposedIsCreated(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->expects($this->once())->method('impose')->with(
			'griffier',
			['scope' => 'item', 'target' => 'item-1', 'ground' => 'ground-gw', 'imposedBy' => 'body', 'imposedByBody' => 'body-raad']
		)->willReturn(['restriction' => ['id' => 'r-1', 'lifecycle' => 'imposed'], 'classifier' => 'closed']);

		$response = $this->controller(uid: 'griffier', imposer: $imposer)->impose();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('closed', $response->getData()['classifier']);
	}//end testImposedIsCreated()

	/**
	 * A refusal answers with its own status and message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testARefusalKeepsItsStatus(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->method('impose')->willThrowException(new ConfidentialityImposeRefusedException(message: 'Only the chair...', status: 403));

		$response = $this->controller(uid: 'member', imposer: $imposer)->impose();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Only the chair...', $response->getData()['message']);
	}//end testARefusalKeepsItsStatus()

	/**
	 * Chairs and secretaries are not administrators: the endpoint is NoAdminRequired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheEndpointIsOpenToNonAdministrators(): void {
		$method = new ReflectionMethod(ConfidentialityRestrictionController::class, 'impose');

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
	}//end testTheEndpointIsOpenToNonAdministrators()
}//end class
