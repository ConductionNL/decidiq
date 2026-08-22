<?php

/**
 * Unit tests for ParticipantUuidLookup.
 *
 * The scoped lookup exists because identity in this app is PER GOVERNANCE BODY,
 * not per person: someone who sits on two boards has two Participant objects,
 * and an unscoped lookup returns whichever one the store lists first. Feeding
 * that into a roster check compares an identity from one body against the
 * invited roster of another, which rejects a legitimately invited member with a
 * message that reads like a correct refusal.
 *
 * These tests pin exactly that: same Nextcloud user, two participant rows, and
 * the answer must depend on the body asked about.
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

use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies Nextcloud-UID to Participant-UUID resolution, scoped and unscoped.
 */
final class ParticipantUuidLookupTest extends TestCase {

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The service under test.
	 *
	 * @var ParticipantUuidLookup
	 */
	private ParticipantUuidLookup $lookup;

	/**
	 * Set up test fixtures.
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

		$this->lookup = new ParticipantUuidLookup(
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data The serialised participant payload.
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
	 * The unscoped lookup answers with the first participant it is given.
	 *
	 * @return void
	 */
	public function testForNextcloudUserReturnsFirstMatch(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['uuid' => 'participant-a', 'governanceBody' => 'body-1'])]
		);

		$this->assertSame('participant-a', $this->lookup->forNextcloudUser(nextcloudUid: 'bestuurslid'));

	}//end testForNextcloudUserReturnsFirstMatch()

	/**
	 * No participant rows means no identity — null, never an empty string.
	 *
	 * @return void
	 */
	public function testForNextcloudUserReturnsNullWhenNoParticipant(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$this->assertNull($this->lookup->forNextcloudUser(nextcloudUid: 'outsider'));

	}//end testForNextcloudUserReturnsNullWhenNoParticipant()

	/**
	 * `id` stands in when the payload carries no `uuid`.
	 *
	 * @return void
	 */
	public function testForNextcloudUserFallsBackToIdWhenUuidAbsent(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['id' => 'participant-by-id'])]
		);

		$this->assertSame('participant-by-id', $this->lookup->forNextcloudUser(nextcloudUid: 'bestuurslid'));

	}//end testForNextcloudUserFallsBackToIdWhenUuidAbsent()

	/**
	 * THE DEFECT THIS CLASS EXISTS FOR.
	 *
	 * One user, two boards, two participant rows. The scoped lookup must answer
	 * with the identity held on the body asked about — not the first row.
	 *
	 * @return void
	 */
	public function testForNextcloudUserInBodyPicksTheIdentityForThatBody(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity(['uuid' => 'participant-on-board-1', 'governanceBody' => 'body-1']),
				$this->entity(['uuid' => 'participant-on-board-2', 'governanceBody' => 'body-2']),
			]
		);

		$this->assertSame(
			'participant-on-board-2',
			$this->lookup->forNextcloudUserInBody(nextcloudUid: 'bestuurslid', governanceBodyId: 'body-2'),
			'the scoped lookup must skip the identity held on a different board'
		);

	}//end testForNextcloudUserInBodyPicksTheIdentityForThatBody()

	/**
	 * The body link is also honoured when it lives in `@self.relations`.
	 *
	 * @return void
	 */
	public function testForNextcloudUserInBodyReadsTheRelationsForm(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity(['uuid' => 'participant-on-board-1', 'governanceBody' => 'body-1']),
				$this->entity(
					[
						'uuid' => 'participant-via-relations',
						'@self' => ['relations' => ['governanceBody' => 'body-2']],
					]
				),
			]
		);

		$this->assertSame(
			'participant-via-relations',
			$this->lookup->forNextcloudUserInBody(nextcloudUid: 'bestuurslid', governanceBodyId: 'body-2')
		);

	}//end testForNextcloudUserInBodyReadsTheRelationsForm()

	/**
	 * A member of no such body resolves to null rather than to someone else.
	 *
	 * @return void
	 */
	public function testForNextcloudUserInBodyReturnsNullWhenNotOnThatBody(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['uuid' => 'participant-on-board-1', 'governanceBody' => 'body-1'])]
		);

		$this->assertNull(
			$this->lookup->forNextcloudUserInBody(nextcloudUid: 'bestuurslid', governanceBodyId: 'body-9'),
			'belonging to no such body must not fall back to an unrelated identity'
		);

	}//end testForNextcloudUserInBodyReturnsNullWhenNotOnThatBody()

	/**
	 * Empty inputs short-circuit before any store call.
	 *
	 * @return void
	 */
	public function testForNextcloudUserInBodyRejectsEmptyInputsWithoutQuerying(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertNull($this->lookup->forNextcloudUserInBody(nextcloudUid: '', governanceBodyId: 'body-1'));
		$this->assertNull($this->lookup->forNextcloudUserInBody(nextcloudUid: 'bestuurslid', governanceBodyId: ''));

	}//end testForNextcloudUserInBodyRejectsEmptyInputsWithoutQuerying()
}//end class
