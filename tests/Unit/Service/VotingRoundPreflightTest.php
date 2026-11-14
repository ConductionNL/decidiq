<?php

/**
 * VotingRoundPreflight: the voting methods a body allows.
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
 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The allowed-methods check reads the body and fails closed when it cannot.
 */
class VotingRoundPreflightTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	private VotingRoundPreflight $preflight;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->preflight = new VotingRoundPreflight(
			logger: new NullLogger(),
			motionService: $this->createMock(MotionService::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			templateService: $this->createMock(ProcessTemplateService::class),
			objectService: $this->objects,
		);

	}//end setUp()

	/**
	 * Answer the body lookup with this row.
	 *
	 * @param array<string, mixed>|null $row The body, or null for none.
	 *
	 * @return void
	 */
	private function body(?array $row): void {
		$entity = null;
		if ($row !== null) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($row);
		}

		$this->objects->expects($this->once())->method('find')->willReturnCallback(
			static function (mixed ...$args) use ($entity): ?ObjectEntity {
				self::assertSame('body-1', $args[0]);
				self::assertContains('governance-body', $args);
				return $entity;
			}
		);

	}//end body()

	public function testTheBodysAllowedMethodsAreReadAndCleaned(): void {
		$this->body(['allowedVotingMethods' => ['for-against-abstain', '', 7, 'weighted']]);

		self::assertSame(['for-against-abstain', 'weighted'], $this->preflight->allowedMethodsOf(governanceBodyId: 'body-1'));

	}//end testTheBodysAllowedMethodsAreReadAndCleaned()

	public function testAnAllowedMethodPassesAndAnotherIsRefusedByName(): void {
		$this->body(['allowedVotingMethods' => ['for-against-abstain']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Voting method 'ranked-choice' is not allowed in this body. Allowed: for-against-abstain");
		$this->preflight->assertMethodAllowed(governanceBodyId: 'body-1', votingMethod: 'ranked-choice');

	}//end testAnAllowedMethodPassesAndAnotherIsRefusedByName()

	public function testWithoutABodyOrADeclarationEveryMethodIsAllowed(): void {
		$this->body(null);
		$this->preflight->assertMethodAllowed(governanceBodyId: 'body-1', votingMethod: 'show-of-hands');
		$this->preflight->assertMethodAllowed(governanceBodyId: null, votingMethod: 'show-of-hands');
		$this->preflight->assertMethodAllowed(governanceBodyId: '', votingMethod: 'show-of-hands');

		self::assertSame([], $this->preflight->allowedMethodsOf(governanceBodyId: null));

	}//end testWithoutABodyOrADeclarationEveryMethodIsAllowed()

	public function testABodyThatCannotBeReadRefuses(): void {
		$this->objects->method('find')->willThrowException(new \Exception('db down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot open voting round: the governance body could not be read');
		$this->preflight->assertMethodAllowed(governanceBodyId: 'body-1', votingMethod: 'for-against-abstain');

	}//end testABodyThatCannotBeReadRefuses()
}//end class
