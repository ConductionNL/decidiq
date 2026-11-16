<?php

/**
 * Unit tests for MinutesCorrectionController at its edges.
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
 * @spec openspec/specs/resolution-minutes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\MinutesCorrectionController;
use OCA\Decidiq\Kernel\Contracts\MinutesAccessGuardInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\Decidiq\Tests\Support\LegacyObjectArgs;

/**
 * Tests for MinutesCorrectionController at its edges.
 *
 * @covers \OCA\Decidiq\Controller\MinutesCorrectionController
 */
class MinutesCorrectionControllerEdgeTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * A controller over these params whose guard allows everything.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return MinutesCorrectionController The controller under test.
	 */
	private function controller(array $params): MinutesCorrectionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$guard = $this->createMock(MinutesAccessGuardInterface::class);
		$guard->method('requireParticipant')->willReturn(null);
		$guard->method('requireChairOrAdmin')->willReturn(null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('raadslid');
		$user->method('getDisplayName')->willReturn('Het Raadslid');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->objects = $this->createMock(ObjectServiceInterface::class);

		return new MinutesCorrectionController(request: $request, accessGuard: $guard, objectService: $this->objects, userSession: $session);
	}//end controller()

	/**
	 * A stored minutes entity with this data.
	 *
	 * @param array<string, mixed> $data The serialised minutes.
	 *
	 * @return ObjectEntity The entity double.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * A new correction is appended after the existing ones, trimmed, and the whole list saved.
	 *
	 * @return void
	 */
	public function testANewCorrectionIsAppendedToTheExistingOnes(): void {
		$controller = $this->controller(params: ['text' => '  Item 5 had 12 votes.  ']);
		$existing = ['id' => 'c-0', 'author' => 'other', 'text' => 'Earlier', 'status' => 'accepted'];
		$this->objects->method('find')->willReturn($this->entity(['lifecycle' => 'draft', 'corrections' => [$existing]]));
		$saved = null;
		$this->objects->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, mixed ...$rest) use (&$saved): ObjectEntity {
				$rest = array_slice(LegacyObjectArgs::positional(method: 'saveObject', args: [$object, ...$rest]), 1);
				$saved = [$object, $rest];
				return $this->entity($object);
			}
		);

		$response = $controller->addCorrection(minutesId: 'm-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$corrections = $response->getData()['corrections'];
		self::assertCount(2, $corrections);
		self::assertSame($existing, $corrections[0]);
		self::assertSame('Item 5 had 12 votes.', $corrections[1]['text']);
		self::assertSame('raadslid', $corrections[1]['author']);
		self::assertSame('Het Raadslid', $corrections[1]['authorName']);
		self::assertSame('proposed', $corrections[1]['status']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $corrections[1]['id']);
		self::assertSame($corrections, $saved[0]['corrections']);
	}//end testANewCorrectionIsAppendedToTheExistingOnes()

	/**
	 * Resolving on minutes that cannot be found is 404, and nothing is saved.
	 *
	 * @return void
	 */
	public function testResolvingOnMissingMinutesIsNotFound(): void {
		$controller = $this->controller(params: ['status' => 'accepted']);
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects(self::never())->method('saveObject');

		$response = $controller->resolveCorrection(minutesId: 'm-404', correctionId: 'c-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Minutes not found.'], $response->getData());
	}//end testResolvingOnMissingMinutesIsNotFound()

	/**
	 * Minutes whose corrections are not a list have no correction to resolve.
	 *
	 * @return void
	 */
	public function testMalformedCorrectionsHaveNothingToResolve(): void {
		$controller = $this->controller(params: ['status' => 'rejected']);
		$this->objects->method('find')->willReturn($this->entity(['lifecycle' => 'review', 'corrections' => 'garbage']));
		$this->objects->expects(self::never())->method('saveObject');

		$response = $controller->resolveCorrection(minutesId: 'm-1', correctionId: 'c-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Correction not found.'], $response->getData());
	}//end testMalformedCorrectionsHaveNothingToResolve()

	/**
	 * A failing read while resolving is a 500 without the failure's detail.
	 *
	 * @return void
	 */
	public function testAFailingReadWhileResolvingIsHidden(): void {
		$controller = $this->controller(params: ['status' => 'accepted']);
		$this->objects->method('find')->willThrowException(new RuntimeException('database password wrong'));

		$response = $controller->resolveCorrection(minutesId: 'm-1', correctionId: 'c-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Internal server error.'], $response->getData());
	}//end testAFailingReadWhileResolvingIsHidden()
}//end class
