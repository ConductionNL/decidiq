<?php

/**
 * Unit tests for ParticipationLifecycleService.
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
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for lifecycle transitions, server-side deadline guards, and the legacy
 * enum migration.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ParticipationLifecycleServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ParticipationLifecycleService
	 */
	private ParticipationLifecycleService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$container->method('get')->willReturn($this->objectService);

		$this->service = new ParticipationLifecycleService(
			container: $container,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Legacy 'summarised' status normalises to 'results-published'.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testLegacyEnumMigration(): void {
		self::assertSame('results-published', $this->service->normaliseConsultationStatus('summarised'));
		self::assertSame('open', $this->service->normaliseConsultationStatus('open'));
		self::assertSame('results-published', $this->service->normaliseConsultationStatus('results-published'));

	}//end testLegacyEnumMigration()

	/**
	 * draft -> open is a legal consultation transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testConsultationDraftToOpen(): void {
		$future = (new \DateTimeImmutable('+7 days'))->format(\DateTimeInterface::ATOM);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'status' => 'draft', 'submissionDeadline' => $future]));
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(['id' => 'c1', 'status' => 'open']));

		$result = $this->service->transitionConsultation(consultationId: 'c1', newStatus: 'open');
		self::assertSame('open', $result['status']);

	}//end testConsultationDraftToOpen()

	/**
	 * Illegal consultation transition (draft -> closed) is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testConsultationIllegalTransitionRejected(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'status' => 'draft']));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->transitionConsultation(consultationId: 'c1', newStatus: 'closed');

	}//end testConsultationIllegalTransitionRejected()

	/**
	 * Opening with a past deadline is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testConsultationOpenWithPastDeadlineRejected(): void {
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'status' => 'draft', 'submissionDeadline' => $past]));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->transitionConsultation(consultationId: 'c1', newStatus: 'open');

	}//end testConsultationOpenWithPastDeadlineRejected()

	/**
	 * Closed legacy 'summarised' value still transitions to results-published.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testClosedToResultsPublished(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'status' => 'closed']));
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(['id' => 'c1', 'status' => 'results-published']));

		$result = $this->service->transitionConsultation(consultationId: 'c1', newStatus: 'results-published');
		self::assertSame('results-published', $result['status']);

	}//end testClosedToResultsPublished()

	/**
	 * Submissions accepted only while open AND before the deadline (deadline
	 * enforced independently of the stored status).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testConsultationAcceptsSubmissionsDeadlineOverStatus(): void {
		$future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
		$past = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);

		self::assertTrue($this->service->consultationAcceptsSubmissions(['status' => 'open', 'submissionDeadline' => $future]));
		// Stored status still 'open' but deadline passed -> closed window.
		self::assertFalse($this->service->consultationAcceptsSubmissions(['status' => 'open', 'submissionDeadline' => $past]));
		self::assertFalse($this->service->consultationAcceptsSubmissions(['status' => 'closed', 'submissionDeadline' => $future]));

	}//end testConsultationAcceptsSubmissionsDeadlineOverStatus()

	/**
	 * Budget round draft -> submission is legal; voting deadline guard works.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testBudgetTransitionAndVoteWindow(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'b1', 'status' => 'draft']));
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(['id' => 'b1', 'status' => 'submission']));
		$result = $this->service->transitionBudgetRound(budgetId: 'b1', newStatus: 'submission');
		self::assertSame('submission', $result['status']);

		$future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
		self::assertTrue($this->service->budgetAcceptsVotes(['status' => 'voting', 'votingDeadline' => $future]));
		self::assertFalse($this->service->budgetAcceptsVotes(['status' => 'voting', 'votingDeadline' => $past]));
		self::assertFalse($this->service->budgetAcceptsVotes(['status' => 'submission', 'votingDeadline' => $future]));

	}//end testBudgetTransitionAndVoteWindow()

}//end class
