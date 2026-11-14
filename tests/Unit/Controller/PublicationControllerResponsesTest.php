<?php

/**
 * Tests: every answer of PublicationController (session, input, guard, service result, failures).
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);
namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\PublicationController;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\PublicationService;
use OCA\Decidiq\Service\PublicationStaffGuard;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every answer of PublicationController: session, input, staff guard,
 * the service result and each failure's status and message.
 */
class PublicationControllerResponsesTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params  Request parameters.
	 * @param \Closure             $service Configures the PublicationService mock.
	 * @param string|null          $uid     The session user.
	 * @param string               $outcome The staff guard's answer.
	 *
	 * @return PublicationController
	 */
	private function controller(array $params, \Closure $service, ?string $uid='chair', string $outcome=PublicationStaffGuard::ALLOWED): PublicationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);
		$publications = $this->createMock(PublicationService::class);
		$service($publications);
		$guard = $this->createMock(PublicationStaffGuard::class);
		$guard->method('currentUid')->willReturn($uid);
		$guard->method('checkSource')->willReturn($outcome);
		$guard->method('checkRecord')->willReturn($outcome);

		return new PublicationController($request, $publications, $guard);
	}//end controller()

	/**
	 * The status and body of a response.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function answer(\OCP\AppFramework\Http\JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * A service that throws $e from $method.
	 *
	 * @param string     $method The method.
	 * @param \Throwable $e      The failure.
	 *
	 * @return \Closure
	 */
	private function throwing(string $method, \Throwable $e): \Closure {
		return static function ($mock) use ($method, $e): void {
			$mock->method($method)->willThrowException($e);
		};
	}//end throwing()

	/**
	 * Publish: session, input, guard and success answers.
	 *
	 * @return void
	 */
	public function testPublishAnswers(): void {
		$ok = ['sourceType' => 'decision', 'sourceId' => 'd-1'];
		$never = function ($mock): void {
			$mock->expects($this->never())->method('publish');
		};
		$badInput = [400, ['message' => 'sourceType (decision|agenda|minutes|activity|incoming-document) and sourceId are required.']];

		$this->assertSame([401, ['message' => 'Authentication required.']], $this->answer($this->controller($ok, $never, null)->publish()));
		$this->assertSame($badInput, $this->answer($this->controller(['sourceType' => 'meeting', 'sourceId' => 'd-1'], $never)->publish()));
		$this->assertSame($badInput, $this->answer($this->controller(['sourceType' => 'agenda'], $never)->publish()));
		$this->assertSame(
			[403, ['message' => 'Forbidden: governance-body authority (chair or secretary) required to publish.']],
			$this->answer($this->controller($ok, $never, 'chair', PublicationStaffGuard::FORBIDDEN)->publish())
		);

		$created = function ($mock): void {
			$mock->expects($this->once())->method('publish')->with('activity', 'a-1', 'chair')->willReturn(['id' => 'r-1']);
		};
		$this->assertSame([201, ['id' => 'r-1']], $this->answer($this->controller(['sourceType' => 'activity', 'sourceId' => 'a-1'], $created)->publish()));
	}//end testPublishAnswers()

	/**
	 * Publish and rectify map their failures the same way.
	 *
	 * @return void
	 */
	public function testPublishAndRectifyFailures(): void {
		$cases = [
			[new AccessDeniedException('Not eligible.'), [422, ['message' => 'Not eligible.']]],
			[new MissingObjectException('Gone.'), [404, ['message' => 'Gone.']]],
			[new ConfidentialityUnreadableException('Try later.'), [503, ['message' => 'Try later.']]],
			[new \InvalidArgumentException('Bad.'), [500, ['message' => 'Internal server error.']]],
			[new RuntimeException('secret detail'), [500, ['message' => 'Internal server error.']]],
		];
		foreach ($cases as [$e, $expected]) {
			$publish = $this->controller(['sourceType' => 'minutes', 'sourceId' => 'm-1'], $this->throwing('publish', $e));
			$this->assertSame($expected, $this->answer($publish->publish()), 'publish ' . get_class($e));
			$rectify = $this->controller(['reason' => 'typo'], $this->throwing('rectify', $e));
			$this->assertSame($expected, $this->answer($rectify->rectify('r-1')), 'rectify ' . get_class($e));
		}
	}//end testPublishAndRectifyFailures()

	/**
	 * Withdraw: session, guard, reason, success and failure answers.
	 *
	 * @return void
	 */
	public function testWithdrawAnswers(): void {
		$never = function ($mock): void {
			$mock->expects($this->never())->method('withdraw');
		};
		$this->assertSame([401, ['message' => 'Authentication required.']], $this->answer($this->controller(['reason' => 'x'], $never, null)->withdraw('r-1')));
		$this->assertSame(
			[404, ['message' => 'Publication record not found.']],
			$this->answer($this->controller(['reason' => 'x'], $never, 'chair', PublicationStaffGuard::RECORD_NOT_FOUND)->withdraw('r-1'))
		);
		$this->assertSame(
			[403, ['message' => 'Forbidden: governance-body authority (chair or secretary) required.']],
			$this->answer($this->controller(['reason' => 'x'], $never, 'chair', PublicationStaffGuard::FORBIDDEN)->withdraw('r-1'))
		);
		$this->assertSame([422, ['message' => 'A withdraw reason is required.']], $this->answer($this->controller(['reason' => '  '], $never)->withdraw('r-1')));

		$ok = function ($mock): void {
			$mock->expects($this->once())->method('withdraw')->with('r-1', 'chair', 'Wrong file')->willReturn(['id' => 'r-1', 'status' => 'withdrawn']);
		};
		$this->assertSame([200, ['id' => 'r-1', 'status' => 'withdrawn']], $this->answer($this->controller(['reason' => 'Wrong file'], $ok)->withdraw('r-1')));

		$cases = [
			[new MissingObjectException('Gone.'), [404, ['message' => 'Gone.']]],
			[new \InvalidArgumentException('Already withdrawn.'), [422, ['message' => 'Already withdrawn.']]],
			[new AccessDeniedException('No.'), [500, ['message' => 'Internal server error.']]],
		];
		foreach ($cases as [$e, $expected]) {
			$controller = $this->controller(['reason' => 'x'], $this->throwing('withdraw', $e));
			$this->assertSame($expected, $this->answer($controller->withdraw('r-1')), get_class($e));
		}
	}//end testWithdrawAnswers()

	/**
	 * Rectify: session, guard and success answers; the reason may be empty.
	 *
	 * @return void
	 */
	public function testRectifyAnswers(): void {
		$never = function ($mock): void {
			$mock->expects($this->never())->method('rectify');
		};
		$this->assertSame([401, ['message' => 'Authentication required.']], $this->answer($this->controller([], $never, null)->rectify('r-1')));
		$this->assertSame(
			[404, ['message' => 'Publication record not found.']],
			$this->answer($this->controller([], $never, 'chair', PublicationStaffGuard::RECORD_NOT_FOUND)->rectify('r-1'))
		);

		$ok = function ($mock): void {
			$mock->expects($this->once())->method('rectify')->with('r-1', 'chair', '')->willReturn(['id' => 'r-2']);
		};
		$this->assertSame([201, ['id' => 'r-2']], $this->answer($this->controller([], $ok)->rectify('r-1')));
	}//end testRectifyAnswers()
}//end class
