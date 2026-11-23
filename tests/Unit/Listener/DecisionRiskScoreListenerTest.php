<?php

/**
 * A decision whose type declares a risk expression is saved with its score.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionRiskScoreListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Task 8.2: computed when the route opens, recomputed when a read property changes.
 */
class DecisionRiskScoreListenerTest extends TestCase {

	/**
	 * The decision types as the listener reads them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const TYPES = [
		'subsidie' => [
			'slug'      => 'subsidie',
			'riskScore' => [
				'rules' => [
					['property' => 'amount', 'operator' => 'gte', 'value' => '100000', 'points' => 3],
					['property' => 'category', 'operator' => 'eq', 'value' => 'innovation', 'points' => 2],
				],
				'bands' => [['band' => 'low', 'min' => 0], ['band' => 'medium', 'min' => 3], ['band' => 'high', 'min' => 5]],
			],
		],
		'raadsbesluit' => ['slug' => 'raadsbesluit'],
		'broken'       => ['slug' => 'broken', 'riskScore' => ['rules' => [['property' => 'amount', 'operator' => 'between', 'points' => 1]]]],
	];

	/**
	 * The listener over the types; `$broken` makes every read throw.
	 *
	 * @param bool $broken Whether the register fails.
	 *
	 * @return DecisionRiskScoreListener
	 */
	private function listener(bool $broken = false): DecisionRiskScoreListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true) use ($broken): ?ObjectEntity {
				self::assertSame('decidiq', $options?->register);
				self::assertSame('decision-template', $options?->schema);
				self::assertFalse($_rbac);
				if ($broken === true) {
					throw new RuntimeException('register down');
				}

				$row = (self::TYPES[(string)$id] ?? null);
				return $row === null ? null : $this->entity(row: $row);
			}
		);

		return new DecisionRiskScoreListener(objectService: $objectService, logger: new NullLogger());
	}//end listener()

	/**
	 * An entity carrying a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Sending the decision for approval writes it, and the save carries the
	 * band and the two properties that produced it.
	 *
	 * @return void
	 */
	public function testTheSendThatOpensTheRouteScoresTheDecision(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(row: ['type' => 'subsidie', 'amount' => 250000, 'category' => 'innovation']),
			$this->entity(row: ['type' => 'subsidie', 'amount' => 250000, 'category' => 'innovation'])
		);
		$event->setModifiedData(['approvalRun' => 'run-1', 'approvalEngine' => 'flow']);

		$this->listener()->handle($event);

		self::assertSame(
			[
				'score' => 5,
				'band'  => 'high',
				'basis' => [
					['property' => 'amount', 'value' => '250000', 'points' => 3],
					['property' => 'category', 'value' => 'innovation', 'points' => 2],
				],
			],
			$event->getModifiedData()['riskAssessment']
		);
		self::assertSame('run-1', $event->getModifiedData()['approvalRun']);
	}//end testTheSendThatOpensTheRouteScoresTheDecision()

	/**
	 * A save that lowers the amount scores the decision again from the new value.
	 *
	 * @return void
	 */
	public function testAChangedPropertyScoresTheDecisionAgain(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(row: ['type' => ['id' => 'subsidie'], 'amount' => 250000, 'riskAssessment' => ['score' => 3, 'band' => 'medium', 'basis' => []]]),
			$this->entity(row: ['type' => 'subsidie', 'amount' => 250000])
		);
		$event->setModifiedData(['amount' => 5000]);

		$this->listener()->handle($event);

		self::assertSame(['score' => 0, 'band' => 'low', 'basis' => []], $event->getModifiedData()['riskAssessment']);
	}//end testAChangedPropertyScoresTheDecisionAgain()

	/**
	 * A client cannot set its own score: the server's replaces it.
	 *
	 * @return void
	 */
	public function testASentScoreIsReplaced(): void {
		$event = new ObjectCreatingEvent($this->entity(row: ['type' => 'subsidie', 'amount' => 100000]));
		$event->setModifiedData(['riskAssessment' => ['score' => 0, 'band' => 'low', 'basis' => []]]);

		$this->listener()->handle($event);

		self::assertSame('medium', $event->getModifiedData()['riskAssessment']['band']);
	}//end testASentScoreIsReplaced()

	/**
	 * A type without an expression, or a decision without a type, clears an old score.
	 *
	 * @return void
	 */
	public function testWithoutAnExpressionTheScoreIsCleared(): void {
		$old = ['score' => 5, 'band' => 'high', 'basis' => []];

		$retyped = new ObjectCreatingEvent($this->entity(row: ['type' => 'raadsbesluit', 'riskAssessment' => $old]));
		$this->listener()->handle($retyped);
		self::assertSame(['riskAssessment' => null], $retyped->getModifiedData());

		$untyped = new ObjectCreatingEvent($this->entity(row: ['riskAssessment' => $old]));
		$this->listener()->handle($untyped);
		self::assertSame(['riskAssessment' => null], $untyped->getModifiedData());

		$plain = new ObjectCreatingEvent($this->entity(row: ['title' => 'No type, no score']));
		$this->listener()->handle($plain);
		self::assertSame([], $plain->getModifiedData());
	}//end testWithoutAnExpressionTheScoreIsCleared()

	/**
	 * An unknown type, a failing register or a malformed expression leaves the save alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableTypeOrExpressionLeavesTheSaveAlone(): void {
		foreach ([[['type' => 'unknown'], false], [['type' => 'subsidie'], true], [['type' => 'broken', 'amount' => 1], false]] as [$row, $broken]) {
			$event = new ObjectCreatingEvent($this->entity(row: $row));

			$this->listener(broken: $broken)->handle($event);

			self::assertSame([], $event->getModifiedData());
			self::assertFalse($event->isPropagationStopped());
		}
	}//end testAnUnreadableTypeOrExpressionLeavesTheSaveAlone()

	/**
	 * The listener is subscribed to decision creates and updates.
	 *
	 * @return void
	 */
	public function testTheListenerIsSubscribedOnCreateAndUpdate(): void {
		$events = [];
		foreach (SaveGuardSubscriptions::ALL as $subscription) {
			if ($subscription['listener'] === DecisionRiskScoreListener::class) {
				$events[] = $subscription['event'];
				self::assertSame(['decision'], $subscription['schemas']);
			}
		}

		self::assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class], $events);
	}//end testTheListenerIsSubscribedOnCreateAndUpdate()
}//end class
