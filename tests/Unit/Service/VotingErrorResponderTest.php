<?php

/**
 * Tests for VotingErrorResponder.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use LogicException;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each voting endpoint's exception-to-status mapping.
 *
 * @covers \OCA\Decidiq\Service\VotingErrorResponder
 */
class VotingErrorResponderTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The responder under test.
	 *
	 * @var VotingErrorResponder
	 */
	private VotingErrorResponder $responder;

	/**
	 * Build the responder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->responder = new VotingErrorResponder(logger: $this->logger);
	}//end setUp()

	/**
	 * A successful operation's response passes through every mapping untouched.
	 *
	 * @return void
	 */
	public function testASuccessfulResponsePassesThrough(): void {
		$ok = new JSONResponse(['ok' => true], 201);
		$this->logger->expects(self::never())->method('error');

		self::assertSame($ok, $this->responder->badRequest(fn () => $ok));
		self::assertSame($ok, $this->responder->badRequestOrNotFound(fn () => $ok));
		self::assertSame($ok, $this->responder->invalidOrMissing(fn () => $ok));
		self::assertSame($ok, $this->responder->internalError(fn () => $ok, 'failed', [], 'Opaque'));
	}//end testASuccessfulResponsePassesThrough()

	/**
	 * badRequest() answers 400 with the message for a validation or runtime refusal.
	 *
	 * @return void
	 */
	public function testBadRequestMapsBothRefusalsTo400(): void {
		$invalid = $this->responder->badRequest(fn () => throw new InvalidArgumentException('Choice is required'));
		self::assertSame(400, $invalid->getStatus());
		self::assertSame(['message' => 'Choice is required'], $invalid->getData());

		$runtime = $this->responder->badRequest(fn () => throw new RuntimeException('Round is closed'));
		self::assertSame(400, $runtime->getStatus());
		self::assertSame(['message' => 'Round is closed'], $runtime->getData());
	}//end testBadRequestMapsBothRefusalsTo400()

	/**
	 * badRequest() does not swallow an exception outside its mapping.
	 *
	 * @return void
	 */
	public function testBadRequestLetsOtherExceptionsThrough(): void {
		$this->expectException(LogicException::class);
		$this->responder->badRequest(fn () => throw new LogicException('bug'));
	}//end testBadRequestLetsOtherExceptionsThrough()

	/**
	 * badRequestOrNotFound() answers 404 when the message names a missing object.
	 *
	 * @return void
	 */
	public function testBadRequestOrNotFoundAnswers404ForAMissingObject(): void {
		$response = $this->responder->badRequestOrNotFound(fn () => throw new RuntimeException('Voting round not found'));

		self::assertSame(404, $response->getStatus());
		self::assertSame(['message' => 'Voting round not found'], $response->getData());
	}//end testBadRequestOrNotFoundAnswers404ForAMissingObject()

	/**
	 * badRequestOrNotFound() answers 400 for any other refusal, including a subclass.
	 *
	 * @return void
	 */
	public function testBadRequestOrNotFoundAnswers400ForOtherRefusals(): void {
		$runtime = $this->responder->badRequestOrNotFound(fn () => throw new RuntimeException('Round already open'));
		self::assertSame(400, $runtime->getStatus());
		self::assertSame(['message' => 'Round already open'], $runtime->getData());

		$missing = $this->responder->badRequestOrNotFound(fn () => throw new \UnexpectedValueException('Not Found'));
		self::assertSame(400, $missing->getStatus(), 'The match is case-sensitive on "not found".');
	}//end testBadRequestOrNotFoundAnswers400ForOtherRefusals()

	/**
	 * invalidOrMissing() maps a validation error to 400 and a runtime refusal to 404.
	 *
	 * @return void
	 */
	public function testInvalidOrMissingSplitsValidationFromMissing(): void {
		$invalid = $this->responder->invalidOrMissing(fn () => throw new InvalidArgumentException('Bad weight'));
		self::assertSame(400, $invalid->getStatus());
		self::assertSame(['message' => 'Bad weight'], $invalid->getData());

		$missing = $this->responder->invalidOrMissing(fn () => throw new RuntimeException('Gone'));
		self::assertSame(404, $missing->getStatus());
		self::assertSame(['message' => 'Gone'], $missing->getData());
	}//end testInvalidOrMissingSplitsValidationFromMissing()

	/**
	 * internalError() logs the failure with its context and hides the message.
	 *
	 * @return void
	 */
	public function testInternalErrorLogsAndReturnsAnOpaque500(): void {
		$this->logger->expects(self::once())
			->method('error')
			->with('Tally failed', ['roundId' => 'r-1', 'error' => 'db down']);

		$response = $this->responder->internalError(
			fn () => throw new LogicException('db down'),
			'Tally failed',
			['roundId' => 'r-1'],
			'Could not tally the round'
		);

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Could not tally the round'], $response->getData());
	}//end testInternalErrorLogsAndReturnsAnOpaque500()
}//end class
