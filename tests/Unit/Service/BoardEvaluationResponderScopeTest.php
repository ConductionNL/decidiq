<?php

/**
 * Unit tests for BoardEvaluationResponseService::resolveResponder().
 *
 * Which Participant the logged-in user "is" depends on the evaluation, because
 * identity is per governance body. This is the seam that decides that, and the
 * defect it fixes is silent: an unscoped answer makes the roster check reject a
 * legitimately invited member with a refusal that reads as correct, so the
 * cycle's completion count never moves and nobody sees an error.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/board-self-evaluation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardEvaluationResponseService;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the responder identity is resolved against the evaluation's own body.
 */
final class BoardEvaluationResponderScopeTest extends TestCase {

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock participant lookup.
	 *
	 * @var ParticipantUuidLookup&MockObject
	 */
	private ParticipantUuidLookup $participants;

	/**
	 * The service under test.
	 *
	 * @var BoardEvaluationResponseService
	 */
	private BoardEvaluationResponseService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->participants = $this->createMock(ParticipantUuidLookup::class);

		$container->method('get')->willReturn($this->objectService);

		$this->service = new BoardEvaluationResponseService(
			$container,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->participants,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data The serialised evaluation payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The evaluation's governanceBody scopes the lookup.
	 *
	 * @return void
	 */
	public function testResolvesAgainstTheEvaluationsGovernanceBody(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['governanceBody' => 'body-2'])
		);

		$this->participants->expects($this->once())
			->method('forNextcloudUserInBody')
			->with('bestuurslid', 'body-2')
			->willReturn('participant-on-board-2');
		$this->participants->expects($this->never())->method('forNextcloudUser');

		$this->assertSame(
			'participant-on-board-2',
			$this->service->resolveResponder(evaluationId: 'evaluation-1', nextcloudUid: 'bestuurslid')
		);

	}//end testResolvesAgainstTheEvaluationsGovernanceBody()

	/**
	 * The body link is also read from `@self.relations`.
	 *
	 * @return void
	 */
	public function testReadsTheGovernanceBodyFromRelations(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['@self' => ['relations' => ['governanceBody' => 'body-7']]])
		);

		$this->participants->expects($this->once())
			->method('forNextcloudUserInBody')
			->with('bestuurslid', 'body-7')
			->willReturn('participant-7');

		$this->assertSame(
			'participant-7',
			$this->service->resolveResponder(evaluationId: 'evaluation-1', nextcloudUid: 'bestuurslid')
		);

	}//end testReadsTheGovernanceBodyFromRelations()

	/**
	 * With no body on the evaluation the unscoped lookup stands in — the roster
	 * check in submitResponse() remains the gate either way.
	 *
	 * @return void
	 */
	public function testFallsBackToTheUnscopedLookupWhenTheEvaluationHasNoBody(): void {
		$this->objectService->method('find')->willReturn($this->entity([]));

		$this->participants->expects($this->once())
			->method('forNextcloudUser')
			->with('bestuurslid')
			->willReturn('participant-a');
		$this->participants->expects($this->never())->method('forNextcloudUserInBody');

		$this->assertSame(
			'participant-a',
			$this->service->resolveResponder(evaluationId: 'evaluation-1', nextcloudUid: 'bestuurslid')
		);

	}//end testFallsBackToTheUnscopedLookupWhenTheEvaluationHasNoBody()

	/**
	 * A store failure must not become an exception at the controller — it
	 * degrades to the unscoped lookup, which the roster check still gates.
	 *
	 * @return void
	 */
	public function testStoreFailureDegradesToTheUnscopedLookup(): void {
		$this->objectService->method('find')->willThrowException(new \RuntimeException('store down'));

		$this->participants->expects($this->once())
			->method('forNextcloudUser')
			->with('bestuurslid')
			->willReturn('participant-a');

		$this->assertSame(
			'participant-a',
			$this->service->resolveResponder(evaluationId: 'evaluation-1', nextcloudUid: 'bestuurslid')
		);

	}//end testStoreFailureDegradesToTheUnscopedLookup()

	/**
	 * Empty inputs short-circuit before the store is touched.
	 *
	 * @return void
	 */
	public function testEmptyInputsResolveToNullWithoutQuerying(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertNull($this->service->resolveResponder(evaluationId: '', nextcloudUid: 'bestuurslid'));
		$this->assertNull($this->service->resolveResponder(evaluationId: 'evaluation-1', nextcloudUid: ''));

	}//end testEmptyInputsResolveToNullWithoutQuerying()
}//end class
