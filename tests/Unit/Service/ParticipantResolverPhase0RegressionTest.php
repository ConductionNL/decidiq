<?php

/**
 * ParticipantResolver Phase-0 Regression Tests.
 *
 * Locks the Phase-0 fix: meeting participants are queried via the OpenRegister
 * `_relations.governance-body` filter (presence-only in OR) and then id-scoped
 * client-side, so a participant linked to a DIFFERENT governance body is not
 * returned. Mocks return real ObjectEntity instances so they satisfy the live
 * ObjectService::find()/findAll() return types (codeberg #90).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase-0 regression tests for ParticipantResolver.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class ParticipantResolverPhase0RegressionTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The service under test.
	 *
	 * @var ParticipantResolver
	 */
	private ParticipantResolver $resolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->container->method('get')->willReturn($this->objectService);

		$this->resolver = new ParticipantResolver(
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data The serialised object payload.
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
	 * resolveMeetingParticipants() reads the full participant set
	 * (`findAll([])`) and filters in PHP via relationsReference(), keeping only
	 * those whose relations actually reference the resolved governance body id.
	 *
	 * Server-side `_relations.*` filtering was intentionally dropped (see the
	 * NOTE in ParticipantResolver::resolveMeetingParticipants): OR-object-API
	 * participants store the link as a flat camelCase field, which the
	 * structured `_relations.governance-body` filter never matched — silently
	 * returning an empty list and 403'ing seeded chairs. This regression test
	 * now pins the PHP-side scoping contract.
	 *
	 * @return void
	 */
	public function testResolveMeetingParticipantsIdScopesByGovernanceBody(): void {
		$meetingId = 'mt1';
		$bodyId = 'gb-target';

		// find() resolves the meeting → governance-body link.
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => $meetingId,
					'relations' => [['schema' => 'governance-body', 'id' => $bodyId]],
				]
			)
		);

		// findAll() reads the full participant set (no server-side relation
		// filter — scoping happens in PHP).
		$this->objectService->expects($this->once())
			->method('findAll')
			->with([])
			->willReturn(
				[
					// Genuinely in the target body.
					$this->entity(['id' => 'p1', 'relations' => [['schema' => 'governance-body', 'id' => $bodyId]]]),
					// Presence-only match leak: relates to a DIFFERENT body — must be dropped.
					$this->entity(['id' => 'p2', 'relations' => [['schema' => 'governance-body', 'id' => 'gb-other']]]),
				]
			);

		$participants = $this->resolver->resolveMeetingParticipants(meetingId: $meetingId);

		$ids = array_column($participants, 'id');
		$this->assertSame(['p1'], $ids);

	}//end testResolveMeetingParticipantsIdScopesByGovernanceBody()

	/**
	 * resolveMeetingParticipants() returns an empty array when the meeting has no
	 * governance-body relation (no body resolvable).
	 *
	 * @return void
	 */
	public function testResolveMeetingParticipantsEmptyWithoutGovernanceBody(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => 'mt-x', 'relations' => []])
		);

		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame([], $this->resolver->resolveMeetingParticipants(meetingId: 'mt-x'));

	}//end testResolveMeetingParticipantsEmptyWithoutGovernanceBody()

	/**
	 * resolveGovernanceBodyId() reads the body id from the meeting's structured
	 * relations list.
	 *
	 * @return void
	 */
	public function testResolveGovernanceBodyIdFromStructuredRelations(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				['relations' => [['schema' => 'governance-body', 'id' => 'gb-99']]]
			)
		);

		$this->assertSame('gb-99', $this->resolver->resolveGovernanceBodyId(meetingId: 'mt9'));

	}//end testResolveGovernanceBodyIdFromStructuredRelations()

}//end class
