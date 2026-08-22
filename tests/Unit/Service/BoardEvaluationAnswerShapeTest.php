<?php

/**
 * Unit tests for the persisted answer shape of a board self-evaluation response.
 *
 * The `EvaluationResponse` schema declares `answers.items.likertValue` as
 * `integer` and `answers.items.freeText` as `string`, neither of them nullable.
 * OpenRegister's validator rejects an explicit null for a typed property — it
 * does NOT read null as "absent" — so writing either key with a null value
 * failed the whole `saveObject` with
 * `Property 'answers.0.freeText' should be type 'string' but is 'null'`.
 *
 * Every likert answer carries a null freeText, so NO likert response could ever
 * be stored. `submitResponse()` returned `success: false`, `recordCompletion()`
 * was never reached, and the cycle's `respondedCount` stayed at 0 while the UI
 * showed nothing wrong — the error NoteCard renders outside the card the count
 * lives in. Observed live as HTTP 422 on
 * `POST /apps/decidesk/api/board-evaluations/{id}/respond`.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\BoardEvaluationResponseService;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies no optional answer field is ever persisted as an explicit null.
 */
final class BoardEvaluationAnswerShapeTest extends TestCase {

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The service under test.
	 *
	 * @var BoardEvaluationResponseService
	 */
	private BoardEvaluationResponseService $service;

	/**
	 * The object handed to saveObject() by the last submitResponse() call.
	 *
	 * @var array<string, mixed>
	 */
	private array $captured = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'lifecycle' => 'open',
					'invitedParticipantIds' => ['participant-1'],
					'invitedMemberCount' => 3,
				]
			)
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				// The response save is the first call; recordCompletion() saves the
				// evaluation afterwards. Only capture the one carrying `answers`.
				$object = ($args[0] ?? []);
				if (is_array($object) === true && isset($object['answers']) === true) {
					$this->captured = $object;
				}

				return $this->entity(is_array($object) ? $object : []);
			}
		);

		$this->service = new BoardEvaluationResponseService(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ParticipantUuidLookup::class),
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data The serialised payload.
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
	 * A likert-only answer must persist WITHOUT a `freeText` key.
	 *
	 * This is the exact submission the live 422 was measured on: two likert
	 * answers, no free text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md
	 */
	public function testALikertAnswerOmitsFreeTextInsteadOfWritingNull(): void {
		$result = $this->service->submitResponse(
			evaluationId: 'eval-1',
			participantId: 'participant-1',
			answers: [
				['questionId' => 'q-strategy-likert', 'dimension' => 'strategy-and-oversight', 'likertValue' => 4],
				['questionId' => 'q-chair-likert', 'dimension' => 'chair-effectiveness', 'likertValue' => 3],
			]
		);

		self::assertTrue($result['success'], (string)($result['message'] ?? ''));
		self::assertCount(2, $this->captured['answers']);

		foreach ($this->captured['answers'] as $index => $answer) {
			self::assertArrayNotHasKey('freeText', $answer, "answers.{$index} must omit freeText, not null it");
			self::assertArrayHasKey('likertValue', $answer);
			self::assertNotContains(null, $answer, "answers.{$index} carries an explicit null");
		}

		self::assertSame(4, $this->captured['answers'][0]['likertValue']);
		self::assertSame('strategy-and-oversight', $this->captured['answers'][0]['dimension']);

	}//end testALikertAnswerOmitsFreeTextInsteadOfWritingNull()

	/**
	 * A free-text-only answer must persist WITHOUT a `likertValue` key — the
	 * mirror case, so the fix is not just "drop freeText".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md
	 */
	public function testAFreeTextAnswerOmitsLikertValueInsteadOfWritingNull(): void {
		$result = $this->service->submitResponse(
			evaluationId: 'eval-1',
			participantId: 'participant-1',
			answers: [['questionId' => 'q-open', 'dimension' => 'culture', 'freeText' => 'More time for strategy.']]
		);

		self::assertTrue($result['success'], (string)($result['message'] ?? ''));
		$answer = $this->captured['answers'][0];
		self::assertArrayNotHasKey('likertValue', $answer);
		self::assertSame('More time for strategy.', $answer['freeText']);
		self::assertNotContains(null, $answer);

	}//end testAFreeTextAnswerOmitsLikertValueInsteadOfWritingNull()

	/**
	 * An answer carrying BOTH keeps both — omission must be driven by the value
	 * being absent, not by the key being optional.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md
	 */
	public function testAnAnswerCarryingBothKeepsBoth(): void {
		$result = $this->service->submitResponse(
			evaluationId: 'eval-1',
			participantId: 'participant-1',
			answers: [['questionId' => 'q-both', 'dimension' => 'culture', 'likertValue' => 2, 'freeText' => 'Mixed.']]
		);

		self::assertTrue($result['success'], (string)($result['message'] ?? ''));
		self::assertSame(2, $this->captured['answers'][0]['likertValue']);
		self::assertSame('Mixed.', $this->captured['answers'][0]['freeText']);

	}//end testAnAnswerCarryingBothKeepsBoth()

	/**
	 * The response never carries the participant id — anonymity is the whole
	 * point of the token, and this fix touches the same payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function testTheStoredResponseNeverCarriesTheParticipantId(): void {
		$this->service->submitResponse(
			evaluationId: 'eval-1',
			participantId: 'participant-1',
			answers: [['questionId' => 'q-chair-likert', 'dimension' => 'chair-effectiveness', 'likertValue' => 5]]
		);

		self::assertStringNotContainsString('participant-1', (string)json_encode($this->captured));

	}//end testTheStoredResponseNeverCarriesTheParticipantId()

}//end class
