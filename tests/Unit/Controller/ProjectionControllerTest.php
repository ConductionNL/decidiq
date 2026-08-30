<?php

/**
 * Unit tests for ProjectionController.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ProjectionController;
use OCA\Decidiq\Service\VotingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProjectionController.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
 */
class ProjectionControllerTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var ProjectionController
	 */
	private ProjectionController $controller;

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock VotingService.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->votingService = $this->createMock(VotingService::class);

		$this->controller = new ProjectionController(
			request: $this->request,
			votingService: $this->votingService,
		);

	}//end setUp()

	/**
	 * publicState() returns 404 when the VotingRound does not exist.
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
	 *
	 * @return void
	 */
	public function testPublicStateReturns404WhenRoundNotFound(): void {
		$this->votingService
			->expects($this->once())
			->method('getPublicState')
			->with(votingRoundId: 'nonexistent-uuid')
			->willReturn(null);

		$result = $this->controller->publicState(id: 'nonexistent-uuid');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublicStateReturns404WhenRoundNotFound()

	/**
	 * publicState() returns 200 with aggregate state for an existing round.
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
	 *
	 * @return void
	 */
	public function testPublicStateReturns200WithAggregateState(): void {
		$publicState = [
			'votingRoundId' => 'round-uuid',
			'totalVotes' => 10,
			'votesFor' => 6,
			'votesAgainst' => 3,
			'votesAbstain' => 1,
		];

		$this->votingService
			->expects($this->once())
			->method('getPublicState')
			->with(votingRoundId: 'round-uuid')
			->willReturn($publicState);

		$result = $this->controller->publicState(id: 'round-uuid');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame($publicState, $result->getData());

	}//end testPublicStateReturns200WithAggregateState()

}//end class
