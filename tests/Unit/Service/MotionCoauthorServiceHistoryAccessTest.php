<?php

/**
 * Unit tests for MotionCoauthorService::getHistory() access control.
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
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidesk\Service\MotionCoauthorService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Read access to a motion's version history is privileged.
 *
 * `getHistory()` shipped without the `checkMotionAccess()` call its three
 * siblings (`addCoauthor`, `removeCoauthor`, `updateMotionText`) all make, so
 * `GET /api/motions/{id}/history` — `@NoAdminRequired` — served any
 * authenticated user the full revision list of any motion by UUID
 * (OWASP A01:2021, Broken Access Control).
 *
 * ⚠️ These tests exercise the SERVICE, deliberately. The controller test suite
 * mocks MotionCoauthorService away, so a controller-level test can only prove
 * that a thrown exception maps to 403 — it cannot prove anything throws. The
 * guard lives here, so the test that proves it lives here too.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
 */
class MotionCoauthorServiceHistoryAccessTest extends TestCase {

	/**
	 * The revision list under test — the payload that must not leak.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const HISTORY = [
		['version' => 2, 'author' => 'raadslid', 'changeSummary' => 'Herformulering dictum'],
		['version' => 1, 'author' => 'raadslid', 'changeSummary' => 'Eerste versie'],
	];

	/**
	 * Service under test.
	 *
	 * @var MotionCoauthorService
	 */
	private MotionCoauthorService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->service = new MotionCoauthorService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Seed one motion, plus the participant lookup checkMotionAccess performs
	 * when the caller is not the owner.
	 *
	 * @param string $owner Motion owner NC uid (@self.owner)
	 * @param array<int, string> $coAuthors Participant UUIDs listed as co-authors
	 * @param string|null $participantUuid Participant UUID the caller resolves to
	 *
	 * @return void
	 */
	private function seedMotion(string $owner, array $coAuthors = [], ?string $participantUuid = null): void {
		$motion = $this->createMock(ObjectEntityInterface::class);
		$motion->method('jsonSerialize')->willReturn(
			[
				'id' => 'motion-1',
				'decisionType' => 'motion',
				'coAuthors' => $coAuthors,
				'versionHistory' => self::HISTORY,
				'@self' => ['owner' => $owner],
			]
		);
		$this->objectService->method('find')->willReturn($motion);

		$participants = [];
		if ($participantUuid !== null) {
			$participant = $this->createMock(ObjectEntityInterface::class);
			$participant->method('jsonSerialize')->willReturn(['uuid' => $participantUuid]);
			$participants = [$participant];
		}

		$this->objectService->method('findAll')->willReturn($participants);

	}//end seedMotion()

	/**
	 * THE REGRESSION TEST. An authenticated user who is neither the proposer
	 * nor a co-author must not be able to read the history.
	 *
	 * Before the guard was added this call returned self::HISTORY.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
	 */
	public function testHistoryIsRefusedToAStranger(): void {
		$this->seedMotion(owner: 'raadslid');

		$this->expectException(InvalidArgumentException::class);

		$this->service->getHistory(motionId: 'motion-1', callerUid: 'buitenstaander');

	}//end testHistoryIsRefusedToAStranger()

	/**
	 * The proposer reads their own motion's history.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
	 */
	public function testHistoryIsReturnedToTheProposer(): void {
		$this->seedMotion(owner: 'raadslid');

		self::assertSame(
			self::HISTORY,
			$this->service->getHistory(motionId: 'motion-1', callerUid: 'raadslid')
		);

	}//end testHistoryIsReturnedToTheProposer()

	/**
	 * A listed co-author reads the history too — the guard scopes by
	 * authorship, it does not simply lock the object to its creator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
	 */
	public function testHistoryIsReturnedToACoAuthor(): void {
		$this->seedMotion(
			owner: 'raadslid',
			coAuthors: ['participant-9'],
			participantUuid: 'participant-9'
		);

		self::assertSame(
			self::HISTORY,
			$this->service->getHistory(motionId: 'motion-1', callerUid: 'medeindiener')
		);

	}//end testHistoryIsReturnedToACoAuthor()

	/**
	 * A null caller uid is the documented admin/background-job bypass, and is
	 * the shape the controller hands in for an instance admin. It must keep
	 * working, or the guard would lock admins out of their own instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
	 */
	public function testHistoryWithNullCallerUidSkipsTheCheck(): void {
		$this->seedMotion(owner: 'raadslid');

		self::assertSame(
			self::HISTORY,
			$this->service->getHistory(motionId: 'motion-1', callerUid: null)
		);

	}//end testHistoryWithNullCallerUidSkipsTheCheck()

}//end class
