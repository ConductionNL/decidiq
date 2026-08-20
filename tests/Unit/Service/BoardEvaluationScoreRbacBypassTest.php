<?php

/**
 * `closeCycle()` reads the response set with RBAC DISABLED, deliberately.
 *
 * `evaluation-response` declares an authorization block that omits `read`, so
 * the raw anonymous answers are closed to everyone but their own author and
 * admins. A chair does not own the other members' responses.
 *
 * Under caller RBAC this query therefore returns NOTHING, and the cycle closes
 * with a vacuous score summary on a healthy 200 — the same silent failure the
 * relation-filter comment in the service records having already happened once,
 * for a different reason. `_rbac: false` is what keeps the scorer able to see
 * the set it is scoring.
 *
 * It is safe because the CALLER is already authorised:
 * `BoardEvaluationController::close()` runs
 * `BoardEvaluationAccessGuard::requireChairOrSecretary()` first, and it is the
 * only caller of this service.
 *
 * This test exists because that argument is one token. Deleting it leaves a
 * suite that still passes and a feature that silently reports zeros.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardEvaluationScoreService;
use OCA\Decidesk\Service\ObjectRelationFilter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scorer reads responses with `_rbac: false`.
 */
class BoardEvaluationScoreRbacBypassTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * The service under test.
	 *
	 * @var BoardEvaluationScoreService
	 */
	private BoardEvaluationScoreService $service;

	/**
	 * Wire the service with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$relationFilter = $this->createMock(ObjectRelationFilter::class);
		$relationFilter->method('filterFor')->willReturn([]);
		$relationFilter->method('matching')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($relationFilter);

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->service = new BoardEvaluationScoreService(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->objectService
		);
	}//end setUp()

	/**
	 * An ObjectEntity double serialising to the given payload.
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
	 * Closing an open cycle reads the responses with RBAC disabled.
	 *
	 * The argument is captured rather than pinned with `->with()`, so the
	 * assertion describes the VALUE the service passed and not merely that
	 * some call happened.
	 *
	 * @return void
	 */
	public function testCloseCycleReadsResponsesWithRbacDisabled(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'eval-1',
					'lifecycle' => 'open',
					'invitedParticipantIds' => [],
					'respondedParticipantIds' => [],
				]
			)
		);

		$captured = null;
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$captured): array {
				$captured = $_rbac;
				return [];
			}
		);

		$this->service->closeCycle(evaluationId: 'eval-1');

		self::assertNotNull(
			$captured,
			'closeCycle() must reach findAll() at all — if it does not, this test is not measuring the '
				. 'read it claims to measure.'
		);
		self::assertFalse(
			$captured,
			'closeCycle() must read the response set with `_rbac: false`. `evaluation-response` closes '
				. '`read` to everyone but the response author, so under caller RBAC a chair sees NOTHING '
				. 'and the cycle closes with a vacuous score summary on a healthy 200.'
		);
	}//end testCloseCycleReadsResponsesWithRbacDisabled()
}//end class
