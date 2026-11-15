<?php

/**
 * Unit tests for MinutesResponder.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\Decidiq\Controller\MinutesResponder;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Which status each kind of failure answers, per kind of minutes operation.
 *
 * @covers \OCA\Decidiq\Controller\MinutesResponder
 */
class MinutesResponderTest extends TestCase {

	/**
	 * An operation that throws.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return callable The operation.
	 */
	private static function throwing(Throwable $error): callable {
		return static function () use ($error): never {
			throw $error;
		};
	}//end throwing()

	/**
	 * The status and body of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed} Status and body.
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Every operation answers what it returns with 200.
	 *
	 * @return void
	 */
	public function testEverySuccessIsTheOperationsResult(): void {
		$responder = new MinutesResponder();
		$ok = static fn (): array => ['id' => 'm-1'];

		$this->assertSame([Http::STATUS_OK, ['id' => 'm-1']], self::answer($responder->runDraft($ok)));
		$this->assertSame([Http::STATUS_OK, ['id' => 'm-1']], self::answer($responder->runLifecycle($ok)));
		$this->assertSame([Http::STATUS_OK, ['id' => 'm-1']], self::answer($responder->runCoded($ok, Http::STATUS_CONFLICT)));
		$this->assertSame([Http::STATUS_OK, ['id' => 'm-1']], self::answer($responder->runInternal($ok)));
	}//end testEverySuccessIsTheOperationsResult()

	/**
	 * A draft: a missing relation is 422, a missing or invalid object 404, anything else upstream 503.
	 *
	 * @return void
	 */
	public function testDraftFailures(): void {
		$responder = new MinutesResponder();

		$this->assertSame([422, ['message' => 'no meeting']], self::answer($responder->runDraft(self::throwing(new MissingRelationException('no meeting')))));
		$this->assertSame([404, ['message' => 'gone']], self::answer($responder->runDraft(self::throwing(new MissingObjectException('gone')))));
		$this->assertSame([404, ['message' => 'bad id']], self::answer($responder->runDraft(self::throwing(new InvalidArgumentException('bad id')))));
		$this->assertSame([503, ['message' => 'store down']], self::answer($responder->runDraft(self::throwing(new RuntimeException('store down')))));
	}//end testDraftFailures()

	/**
	 * A lifecycle step: a missing object is 404, a missing relation or an invalid argument 422, upstream 503.
	 *
	 * @return void
	 */
	public function testLifecycleFailures(): void {
		$responder = new MinutesResponder();

		$this->assertSame([404, ['message' => 'gone']], self::answer($responder->runLifecycle(self::throwing(new MissingObjectException('gone')))));
		$this->assertSame([422, ['message' => 'no meeting']], self::answer($responder->runLifecycle(self::throwing(new MissingRelationException('no meeting')))));
		$this->assertSame([422, ['message' => 'not approvable']], self::answer($responder->runLifecycle(self::throwing(new InvalidArgumentException('not approvable')))));
		$this->assertSame([503, ['message' => 'store down']], self::answer($responder->runLifecycle(self::throwing(new RuntimeException('store down')))));
	}//end testLifecycleFailures()

	/**
	 * A coded operation honours one status from the exception code; every other code is 400.
	 *
	 * @return void
	 */
	public function testCodedFailuresHonourOnlyTheNamedStatus(): void {
		$responder = new MinutesResponder();

		$this->assertSame([404, ['message' => 'gone']], self::answer($responder->runCoded(self::throwing(new MissingObjectException('gone')), 409)));
		$this->assertSame([409, ['message' => 'locked']], self::answer($responder->runCoded(self::throwing(new Exception('locked', 409)), 409)));
		$this->assertSame([400, ['message' => 'locked']], self::answer($responder->runCoded(self::throwing(new Exception('locked', 409)), 403)));
		$this->assertSame([400, ['message' => 'odd']], self::answer($responder->runCoded(self::throwing(new Exception('odd')), 409)));
	}//end testCodedFailuresHonourOnlyTheNamedStatus()

	/**
	 * An internal operation passes a client error code through and hides anything else as a 500.
	 *
	 * @return void
	 */
	public function testInternalFailuresPassClientErrorsAndHideTheRest(): void {
		$responder = new MinutesResponder();

		$this->assertSame([404, ['message' => 'gone']], self::answer($responder->runInternal(self::throwing(new MissingObjectException('gone')))));
		foreach ([400, 403, 404, 409, 422] as $code) {
			$this->assertSame([$code, ['message' => 'refused']], self::answer($responder->runInternal(self::throwing(new LogicException('refused', $code)))));
		}

		$this->assertSame(
			[500, ['message' => 'Internal server error.']],
			self::answer($responder->runInternal(self::throwing(new RuntimeException('secret detail', 500))))
		);
		$this->assertSame(
			[500, ['message' => 'Internal server error.']],
			self::answer($responder->runInternal(self::throwing(new RuntimeException('secret detail', 401))))
		);
	}//end testInternalFailuresPassClientErrorsAndHideTheRest()

	/**
	 * A bad request carries its message.
	 *
	 * @return void
	 */
	public function testBadRequestCarriesItsMessage(): void {
		$this->assertSame([400, ['message' => 'Missing title.']], self::answer((new MinutesResponder())->badRequest('Missing title.')));
	}//end testBadRequestCarriesItsMessage()
}//end class
