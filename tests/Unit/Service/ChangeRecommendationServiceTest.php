<?php

/**
 * Unit tests for ChangeRecommendationService.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;
use OCA\Decidiq\Service\AmendmentTextMerger;
use OCA\Decidiq\Service\ChangeRecommendationService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Accepting works the passage into the text; rejecting only records the decision.
 *
 * @covers \OCA\Decidiq\Service\ChangeRecommendationService
 * @covers \OCA\Decidiq\Exception\ChangeRecommendationRefusedException
 * @uses   \OCA\Decidiq\Service\AmendmentTextMerger
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 */
class ChangeRecommendationServiceTest extends TestCase {

	/**
	 * Stored objects: schema slug to uuid to data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * Every save: [schema, uuid].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $saves = [];

	/**
	 * A deliberating motion and a pending recommendation on its amount.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'decision' => [
				'motion-1' => [
					'decisionType' => 'motion',
					'lifecycle'    => 'deliberating',
					'title'        => 'Verlichting fietspaden 2027',
					'text'         => "De raad besluit:\nEen krediet van 640.000 euro beschikbaar stellen.",
				],
			],
			'change-recommendation' => [
				'rec-1' => [
					'motion'        => 'motion-1',
					'lineFrom'      => 2,
					'lineTo'        => 2,
					'targetPassage' => 'Een krediet van 640.000 euro beschikbaar stellen',
					'proposedText'  => 'Een krediet van 645.000 euro beschikbaar stellen',
					'reason'        => 'Bedrag uit de begrotingswijziging',
					'status'        => 'pending',
				],
			],
		];
	}//end setUp()

	/**
	 * Accepting replaces the passage, keeps the first wording, adds a history
	 * entry naming the recommendation and marks it accepted by the decider.
	 *
	 * @return void
	 */
	public function testAcceptWorksThePassageIntoTheTextAndRecordsTheDecision(): void {
		$result = $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'griffier');

		$motion = $this->objects['decision']['motion-1'];
		$this->assertSame("De raad besluit:\nEen krediet van 645.000 euro beschikbaar stellen.", $motion['text']);
		$this->assertSame("De raad besluit:\nEen krediet van 640.000 euro beschikbaar stellen.", $motion['originalText']);
		$this->assertCount(1, $motion['amendmentHistory']);
		$entry = $motion['amendmentHistory'][0];
		$this->assertSame('rec-1', $entry['recommendation']);
		$this->assertSame('recommendation', $entry['kind']);
		$this->assertSame('passage', $entry['mode']);
		$this->assertArrayNotHasKey('amendment', $entry);

		$recommendation = $this->objects['change-recommendation']['rec-1'];
		$this->assertSame('accepted', $recommendation['status']);
		$this->assertSame('griffier', $recommendation['decidedBy']);
		$this->assertSame('2026-11-14T09:00:00+00:00', $recommendation['decidedAt']);
		$this->assertSame([['decision', 'motion-1'], ['change-recommendation', 'rec-1']], $this->saves);
		$this->assertSame($recommendation, $result['recommendation']);
		$this->assertSame($motion, $result['motion']);
	}//end testAcceptWorksThePassageIntoTheTextAndRecordsTheDecision()

	/**
	 * A second accept is refused: the recommendation is already decided.
	 *
	 * @return void
	 */
	public function testAcceptingTwiceIsRefused(): void {
		$service = $this->service();
		$service->accept(recommendationId: 'rec-1', deciderUid: 'griffier');

		$this->assertRefused(status: 409, message: 'This recommendation has already been decided', call: fn () => $service->accept(recommendationId: 'rec-1', deciderUid: 'griffier'));
		$this->assertCount(2, $this->saves);
	}//end testAcceptingTwiceIsRefused()

	/**
	 * A passage that no longer occurs is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAChangedPassageIsRefusedAndNothingIsWritten(): void {
		$this->objects['decision']['motion-1']['text'] = "De raad besluit:\nEen krediet van 700.000 euro beschikbaar stellen.";
		$before = $this->objects;

		$this->assertRefused(
			status: 409,
			message: 'The passage has changed since this recommendation was made',
			call: fn () => $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'griffier')
		);
		$this->assertSame($before, $this->objects);
		$this->assertSame([], $this->saves);
	}//end testAChangedPassageIsRefusedAndNothingIsWritten()

	/**
	 * A recommendation without a passage would replace the whole text, so it is refused.
	 *
	 * @return void
	 */
	public function testARecommendationWithoutAPassageIsRefused(): void {
		$this->objects['change-recommendation']['rec-1']['targetPassage'] = '  ';

		$this->assertRefused(status: 422, message: 'This recommendation names no passage to replace', call: fn () => $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'griffier'));
		$this->assertSame([], $this->saves);
	}//end testARecommendationWithoutAPassageIsRefused()

	/**
	 * A recommendation already in the history is not applied again.
	 *
	 * @return void
	 */
	public function testARecommendationAlreadyInTheHistoryIsRefused(): void {
		$this->objects['decision']['motion-1']['amendmentHistory'] = [['recommendation' => 'rec-1', 'kind' => 'recommendation']];

		$this->assertRefused(status: 409, message: 'This recommendation is already in the motion text', call: fn () => $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'griffier'));
		$this->assertSame([], $this->saves);
	}//end testARecommendationAlreadyInTheHistoryIsRefused()

	/**
	 * Rejecting writes only the recommendation's status and decider.
	 *
	 * @return void
	 */
	public function testRejectWritesOnlyTheRecommendation(): void {
		$motion = $this->objects['decision']['motion-1'];

		$rejected = $this->service()->reject(recommendationId: 'rec-1', deciderUid: 'voorzitter');

		$this->assertSame('rejected', $rejected['status']);
		$this->assertSame('voorzitter', $rejected['decidedBy']);
		$this->assertSame('2026-11-14T09:00:00+00:00', $rejected['decidedAt']);
		$this->assertSame($motion, $this->objects['decision']['motion-1']);
		$this->assertSame([['change-recommendation', 'rec-1']], $this->saves);
	}//end testRejectWritesOnlyTheRecommendation()

	/**
	 * A motion in voting or later refuses both decisions.
	 *
	 * @return void
	 */
	public function testAMotionPastDeliberationRefusesBoth(): void {
		$this->objects['decision']['motion-1']['lifecycle'] = 'voting';

		$this->assertRefused(status: 409, message: 'The motion text can no longer be changed', call: fn () => $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'voorzitter'));
		$this->assertRefused(status: 409, message: 'The motion text can no longer be changed', call: fn () => $this->service()->reject(recommendationId: 'rec-1', deciderUid: 'voorzitter'));
		$this->assertSame([], $this->saves);
	}//end testAMotionPastDeliberationRefusesBoth()

	/**
	 * An unknown recommendation, or one whose motion is missing or not a motion, is 404.
	 *
	 * @return void
	 */
	public function testMissingObjectsAreNotFound(): void {
		$this->assertRefused(status: 404, message: 'Recommendation not found', call: fn () => $this->service()->reject(recommendationId: 'rec-x', deciderUid: 'voorzitter'));

		$this->objects['decision']['motion-1']['decisionType'] = 'amendment';
		$this->assertRefused(status: 404, message: 'Motion not found', call: fn () => $this->service()->reject(recommendationId: 'rec-1', deciderUid: 'voorzitter'));

		$this->objects['change-recommendation']['rec-1']['motion'] = '';
		$this->assertRefused(status: 404, message: 'Motion not found', call: fn () => $this->service()->accept(recommendationId: 'rec-1', deciderUid: 'voorzitter'));
		$this->assertSame([], $this->saves);
	}//end testMissingObjectsAreNotFound()

	/**
	 * The motion of a recommendation, or null when it is unknown or names none.
	 *
	 * @return void
	 */
	public function testMotionIdOf(): void {
		$service = $this->service();

		$this->assertSame('motion-1', $service->motionIdOf(recommendationId: 'rec-1'));
		$this->assertNull($service->motionIdOf(recommendationId: 'rec-x'));
		$this->objects['change-recommendation']['rec-1']['motion'] = ' ';
		$this->assertNull($service->motionIdOf(recommendationId: 'rec-1'));
	}//end testMotionIdOf()

	/**
	 * Assert that a call is refused with this status and message.
	 *
	 * @param int      $status  The expected HTTP status
	 * @param string   $message The expected message
	 * @param callable $call    The call
	 *
	 * @return void
	 */
	private function assertRefused(int $status, string $message, callable $call): void {
		try {
			$call();
			$this->fail('Expected a refusal');
		} catch (ChangeRecommendationRefusedException $e) {
			$this->assertSame($status, $e->getStatus());
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * The service over an in-memory object service at a fixed time.
	 *
	 * @return ChangeRecommendationService
	 */
	private function service(): ChangeRecommendationService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface => isset($this->objects[(string) $schema][(string) $id]) === true
				? $this->entity(data: $this->objects[(string) $schema][(string) $id])
				: null
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->assertSame('decidiq', $register);
				$this->objects[(string) $schema][(string) $uuid] = $object;
				$this->saves[] = [(string) $schema, (string) $uuid];
				return $this->entity(data: $object);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T09:00:00+00:00'));

		return new ChangeRecommendationService(objectService: $objectService, merger: new AmendmentTextMerger(), time: $time);
	}//end service()

	/**
	 * An object entity holding this data.
	 *
	 * @param array<string, mixed> $data The data
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
