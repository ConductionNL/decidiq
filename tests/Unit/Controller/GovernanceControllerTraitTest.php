<?php

/**
 * Unit tests for GovernanceControllerTrait.
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
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\GovernanceControllerTrait;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Controller\GovernanceControllerTrait
 */
class GovernanceControllerTraitTest extends TestCase {

	/**
	 * A host exposing the trait's protected helpers.
	 *
	 * @var object
	 */
	private object $host;

	protected function setUp(): void {
		$this->host = new class() {
			use GovernanceControllerTrait {
				requireUserOr401 as public;
				bodyParams as public;
				respondFromResult as public;
			}
		};

	}//end setUp()

	public function testRequireUserOr401RefusesAnonymous(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$response = $this->host->requireUserOr401($session);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required.'], $response->getData());

	}//end testRequireUserOr401RefusesAnonymous()

	public function testRequireUserOr401PassesASignedInUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->assertNull($this->host->requireUserOr401($session));

	}//end testRequireUserOr401PassesASignedInUser()

	public function testBodyParamsStripsIdAndRouteByDefault(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'x', '_route' => 'r', 'name' => 'Raad', 'size' => 9]);

		$this->assertSame(['name' => 'Raad', 'size' => 9], $this->host->bodyParams($request));

	}//end testBodyParamsStripsIdAndRouteByDefault()

	public function testBodyParamsHonoursCustomStripKeys(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'x', 'bodyId' => 'b', 'name' => 'Raad']);

		$this->assertSame(['id' => 'x', 'name' => 'Raad'], $this->host->bodyParams($request, ['bodyId']));

	}//end testBodyParamsHonoursCustomStripKeys()

	public function testRespondFromResultReturnsThePayloadOnSuccess(): void {
		$response = $this->host->respondFromResult(['success' => true, 'body' => ['id' => 'b1']], 'body', Http::STATUS_CREATED);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'b1'], $response->getData());

	}//end testRespondFromResultReturnsThePayloadOnSuccess()

	public function testRespondFromResultDefaultsTo200AndANullPayload(): void {
		$response = $this->host->respondFromResult(['success' => true], 'body');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData());

	}//end testRespondFromResultDefaultsTo200AndANullPayload()

	public function testRespondFromResultMapsNotFoundMessagesTo404(): void {
		$response = $this->host->respondFromResult(['success' => false, 'message' => 'Body NOT FOUND'], 'body');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Body NOT FOUND'], $response->getData());

	}//end testRespondFromResultMapsNotFoundMessagesTo404()

	public function testRespondFromResultMapsOtherFailuresTo422(): void {
		$response = $this->host->respondFromResult(['success' => false, 'message' => 'Quorum invalid'], 'body');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Quorum invalid'], $response->getData());

	}//end testRespondFromResultMapsOtherFailuresTo422()

	public function testRespondFromResultTreatsAMissingSuccessFlagAsFailure(): void {
		$response = $this->host->respondFromResult(['body' => ['id' => 'b1']], 'body');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed.'], $response->getData());

	}//end testRespondFromResultTreatsAMissingSuccessFlagAsFailure()
}//end class
