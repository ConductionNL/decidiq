<?php

/**
 * DecisionCompetenceGuard: who may take a decision of a type.
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
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\DecisionCompetenceRefusedException;
use OCA\Decidiq\Service\DecisionCompetenceGuard;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\DecisionCompetenceGuard
 */
class DecisionCompetenceGuardTest extends TestCase {

	private const MANAGEMENT = 'body-management';

	private const DEVELOPMENT = 'body-development';

	/**
	 * Decision types by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $types = [
		'type-budget' => ['id' => 'type-budget', 'competentBody' => self::MANAGEMENT],
		'type-open' => ['id' => 'type-open', 'competentBody' => null],
	];

	/**
	 * Meeting bodies by meeting id.
	 *
	 * @var array<string, string|null>
	 */
	private array $meetings = ['meet-mt' => self::MANAGEMENT, 'meet-dev' => self::DEVELOPMENT, 'meet-none' => null];

	/**
	 * The guard over the types and meetings above.
	 *
	 * @param \Throwable|null $typeFailure    Thrown when a type is read.
	 * @param \Throwable|null $meetingFailure Thrown when a meeting's body is read.
	 *
	 * @return DecisionCompetenceGuard
	 */
	private function guard(?\Throwable $typeFailure = null, ?\Throwable $meetingFailure = null): DecisionCompetenceGuard {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($typeFailure): ?ObjectEntity {
				if ($typeFailure !== null) {
					throw $typeFailure;
				}

				self::assertContains('decision-template', $args);
				if (isset($this->types[$args[0]]) === false) {
					throw new DoesNotExistException('gone');
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($this->types[$args[0]]);
				return $entity;
			}
		);

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveGovernanceBodyId')->willReturnCallback(
			function (string $meetingId) use ($meetingFailure): ?string {
				if ($meetingFailure !== null) {
					throw $meetingFailure;
				}

				return ($this->meetings[$meetingId] ?? null);
			}
		);

		return new DecisionCompetenceGuard(objectService: $objects, participantResolver: $resolver);
	}//end guard()

	/**
	 * Assert the decision is refused with this message.
	 *
	 * @param array<string, mixed>         $decision The decision.
	 * @param string                       $message  The refusal.
	 * @param DecisionCompetenceGuard|null $guard    The guard, default the plain one.
	 *
	 * @return DecisionCompetenceRefusedException The refusal.
	 */
	private function refused(array $decision, string $message, ?DecisionCompetenceGuard $guard = null): DecisionCompetenceRefusedException {
		try {
			($guard ?? $this->guard())->assertCompetent(decision: $decision);
		} catch (DecisionCompetenceRefusedException $e) {
			self::assertSame($message, $e->getMessage());
			return $e;
		}

		self::fail('The decision must be refused: ' . $message);
	}//end refused()

	public function testTheCompetentBodyMayTakeTheDecision(): void {
		$this->guard()->assertCompetent(decision: ['type' => 'type-budget', 'meeting' => 'meet-mt']);
		$this->guard()->assertCompetent(decision: ['type' => ['id' => 'type-budget'], 'meeting' => ['id' => 'meet-mt']]);
		$this->addToAssertionCount(1);
	}//end testTheCompetentBodyMayTakeTheDecision()

	public function testAnotherBodyMayNotTakeIt(): void {
		$this->refused(
			['type' => 'type-budget', 'meeting' => 'meet-dev'],
			'The decision was not saved: only the body its type names as competent may take a decision of this type.'
		);
		$this->refused(
			['type' => 'type-budget'],
			'The decision was not saved: only the body its type names as competent may take a decision of this type.'
		);
	}//end testAnotherBodyMayNotTakeIt()

	public function testATypeWithoutACompetentBodyFallsBackToTheMeetingsBody(): void {
		$this->guard()->assertCompetent(decision: ['type' => 'type-open', 'meeting' => 'meet-dev']);
		$this->addToAssertionCount(1);
	}//end testATypeWithoutACompetentBodyFallsBackToTheMeetingsBody()

	public function testWithoutEitherBodyTheDecisionIsRefused(): void {
		foreach ([['type' => 'type-open'], ['type' => 'type-open', 'meeting' => 'meet-none']] as $decision) {
			$this->refused(
				$decision,
				'The decision was not saved: its type names no competent body and it belongs to no meeting of a body.'
			);
		}
	}//end testWithoutEitherBodyTheDecisionIsRefused()

	public function testAnUnknownTypeIsRefused(): void {
		$this->refused(['type' => 'type-gone', 'meeting' => 'meet-mt'], 'The decision was not saved: its decision type does not exist.');
	}//end testAnUnknownTypeIsRefused()

	public function testAnUnreadableTypeRefusesItDoesNotPermit(): void {
		$failure = new RuntimeException('database gone');
		$refusal = $this->refused(
			['type' => 'type-budget', 'meeting' => 'meet-mt'],
			'The decision was not saved: its decision type could not be read to check who may take it.',
			$this->guard(typeFailure: $failure)
		);
		self::assertSame($failure, $refusal->getPrevious());
	}//end testAnUnreadableTypeRefusesItDoesNotPermit()

	public function testAnUnreadableMeetingRefusesItDoesNotPermit(): void {
		$this->refused(
			['type' => 'type-open', 'meeting' => 'meet-mt'],
			"The decision was not saved: its meeting's body could not be read to check who may take it.",
			$this->guard(meetingFailure: new RuntimeException('database gone'))
		);
	}//end testAnUnreadableMeetingRefusesItDoesNotPermit()

	public function testADecisionWithoutATypeIsNotGovernedByOne(): void {
		$this->guard(typeFailure: new RuntimeException('must not be read'))->assertCompetent(decision: ['meeting' => 'meet-dev', 'type' => '  ']);
		$this->addToAssertionCount(1);
	}//end testADecisionWithoutATypeIsNotGovernedByOne()
}//end class
