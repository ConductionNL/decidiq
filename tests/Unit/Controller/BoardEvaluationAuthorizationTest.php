<?php

/**
 * Authorisation tests for the board self-evaluation ACTION endpoints.
 *
 * These endpoints are `#[NoAdminRequired]` and take a caller-supplied
 * BoardEvaluation id. `requireUserOr401()` answers "is anyone logged in",
 * which the Nextcloud middleware has already settled before the method runs —
 * it is not an authorisation guard, and gate-7 (`no-admin-idor`) used to be
 * cleared by it. The guard that actually answers "may THIS caller act on THIS
 * evaluation" is `BoardEvaluationAccessGuard`, and it is wired REAL here (only
 * its collaborators are doubled) so these tests exercise the shipping rule
 * rather than a mock that always says yes.
 *
 * Every rule is asserted BOTH WAYS: the unauthorised caller is refused AND the
 * authorised caller still succeeds. A guard proven only in the deny direction
 * could be a blanket refusal.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\BoardEvaluationController;
use OCA\Decidiq\Service\BoardEvaluationAccessGuard;
use OCA\Decidiq\Service\BoardEvaluationReportService;
use OCA\Decidiq\Service\BoardEvaluationResponseService;
use OCA\Decidiq\Service\BoardEvaluationScoreService;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Per-object authorisation contract for close / publish / report.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
 */
class BoardEvaluationAuthorizationTest extends TestCase {

	/**
	 * UUID of the evaluation every test acts on.
	 *
	 * @var string
	 */
	private const EVALUATION_ID = 'evaluation-1';

	/**
	 * UUID of the governance body the evaluation belongs to.
	 *
	 * @var string
	 */
	private const BODY_ID = 'body-1';

	/**
	 * Score service double (close).
	 *
	 * @var BoardEvaluationScoreService&MockObject
	 */
	private BoardEvaluationScoreService&MockObject $scoreService;

	/**
	 * Report service double (report).
	 *
	 * @var BoardEvaluationReportService&MockObject
	 */
	private BoardEvaluationReportService&MockObject $reportService;

	/**
	 * Publication service double (publish).
	 *
	 * @var ParticipationPublicationService&MockObject
	 */
	private ParticipationPublicationService&MockObject $publicationService;

	/**
	 * Participant lookup double — resolves "is this UID on that body".
	 *
	 * @var ParticipantUuidLookup&MockObject
	 */
	private ParticipantUuidLookup&MockObject $participants;

	/**
	 * Reset the doubles shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->scoreService = $this->createMock(BoardEvaluationScoreService::class);
		$this->reportService = $this->createMock(BoardEvaluationReportService::class);
		$this->publicationService = $this->createMock(ParticipationPublicationService::class);
		$this->participants = $this->createMock(ParticipantUuidLookup::class);

	}//end setUp()

	/**
	 * Build the controller with a REAL BoardEvaluationAccessGuard over the
	 * given stored evaluation, session uid, admin flag and body roster.
	 *
	 * @param string $uid Nextcloud uid of the caller
	 * @param bool $isAdmin Whether that uid is a Nextcloud admin
	 * @param array<string, mixed> $evaluation Stored BoardEvaluation payload
	 * @param array<int, string> $bodyMemberUids Uids that resolve to a Participant on the body
	 *
	 * @return BoardEvaluationController
	 */
	private function makeController(
		string $uid,
		bool $isAdmin = false,
		array $evaluation = [],
		array $bodyMemberUids = [],
	): BoardEvaluationController {
		$stored = $evaluation;
		if ($stored === []) {
			$stored = [
				'governanceBody' => self::BODY_ID,
				'chairUserId' => 'chair-uid',
				'secretaryUserId' => 'secretary-uid',
				'lifecycle' => 'open',
			];
		}

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($stored);
		$entity->method('getObject')->willReturn($stored);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);

		$this->participants->method('forNextcloudUserInBody')->willReturnCallback(
			static function (string $nextcloudUid, string $governanceBodyId) use ($bodyMemberUids): ?string {
				if ($governanceBodyId === self::BODY_ID && in_array($nextcloudUid, $bodyMemberUids, true) === true) {
					return 'participant-of-' . $nextcloudUid;
				}

				return null;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$guard = new BoardEvaluationAccessGuard(
			$objectService,
			$this->participants,
			$session,
			$groupManager,
		);

		return new BoardEvaluationController(
			$this->createMock(IRequest::class),
			$this->createMock(BoardEvaluationResponseService::class),
			$this->scoreService,
			$this->reportService,
			$this->publicationService,
			$session,
			$guard,
		);

	}//end makeController()

	/**
	 * DENY — a signed-in user who is neither the body's chair nor its
	 * secretary cannot close the cycle, and the scoring pass never runs.
	 *
	 * The `never()` matters: before the guard, `closeCycle()` computed the
	 * whole score summary and only OpenRegister's later refusal of the
	 * `lifecycle` write stopped the save — surfacing as a generic 422.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function testCloseByOutsiderIs403AndNeverScores(): void {
		$this->scoreService->expects($this->never())->method('closeCycle');

		$controller = $this->makeController(uid: 'outsider');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->close(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testCloseByOutsiderIs403AndNeverScores()

	/**
	 * DENY — an ordinary member OF THE BODY still cannot close the cycle.
	 *
	 * Closing is a presiding-officer act; membership is not enough. Without
	 * this case the deny test above would also pass on a guard that merely
	 * required body membership.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function testCloseByPlainBoardMemberIs403(): void {
		$this->scoreService->expects($this->never())->method('closeCycle');

		$controller = $this->makeController(uid: 'member-uid', bodyMemberUids: ['member-uid']);

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->close(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testCloseByPlainBoardMemberIs403()

	/**
	 * ALLOW — the body's chair closes the cycle and gets the scored evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	public function testCloseByChairSucceeds(): void {
		$this->scoreService->expects($this->once())->method('closeCycle')
			->with(evaluationId: self::EVALUATION_ID)
			->willReturn(['success' => true, 'evaluation' => ['id' => self::EVALUATION_ID, 'lifecycle' => 'closed']]);

		$controller = $this->makeController(uid: 'chair-uid');

		$response = $controller->close(id: self::EVALUATION_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('closed', $response->getData()['lifecycle']);

	}//end testCloseByChairSucceeds()

	/**
	 * ALLOW — the body's secretary closes the cycle too; the schema's
	 * `lifecycle.update` rule names both officers, so the guard must as well.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function testCloseBySecretarySucceeds(): void {
		$this->scoreService->expects($this->once())->method('closeCycle')
			->willReturn(['success' => true, 'evaluation' => ['id' => self::EVALUATION_ID]]);

		$controller = $this->makeController(uid: 'secretary-uid');

		self::assertSame(
			Http::STATUS_OK,
			$controller->close(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testCloseBySecretarySucceeds()

	/**
	 * ALLOW — a Nextcloud admin bypasses the officer check, matching
	 * OpenRegister's own property-RBAC, which returns early for admins.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function testCloseByAdminSucceeds(): void {
		$this->scoreService->expects($this->once())->method('closeCycle')
			->willReturn(['success' => true, 'evaluation' => ['id' => self::EVALUATION_ID]]);

		$controller = $this->makeController(uid: 'admin', isAdmin: true);

		self::assertSame(
			Http::STATUS_OK,
			$controller->close(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testCloseByAdminSucceeds()

	/**
	 * DENY — an outsider cannot publish the aggregate summary, and the
	 * publication stack is never entered.
	 *
	 * This is the case that was worst before the guard: publishing sets
	 * `publicationDate`, `publishSummary()` swallows OpenRegister's refusal in
	 * a `catch`, and the endpoint answered **200** with
	 * `publishedPredicateSet: false` — indistinguishable from success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testPublishByOutsiderIs403AndNeverPublishes(): void {
		$this->publicationService->expects($this->never())->method('publishEvaluationResults');

		$controller = $this->makeController(uid: 'outsider');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->publish(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testPublishByOutsiderIs403AndNeverPublishes()

	/**
	 * ALLOW — the chair publishes and gets the publication result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testPublishByChairSucceeds(): void {
		$this->publicationService->expects($this->once())->method('publishEvaluationResults')
			->with(evaluationId: self::EVALUATION_ID)
			->willReturn(['publishedPredicateSet' => true]);

		$controller = $this->makeController(uid: 'chair-uid');

		$response = $controller->publish(id: self::EVALUATION_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['publishedPredicateSet']);

	}//end testPublishByChairSucceeds()

	/**
	 * DENY — a user who is not on the evaluating body at all cannot generate
	 * the report document, and the generator never runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testReportByOutsiderIs403AndNeverGenerates(): void {
		$this->reportService->expects($this->never())->method('generate');

		$controller = $this->makeController(uid: 'outsider', bodyMemberUids: ['member-uid']);

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->report(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testReportByOutsiderIs403AndNeverGenerates()

	/**
	 * ALLOW — an ORDINARY member of the body still generates the report.
	 *
	 * This is the case that keeps the fix from being a functionality
	 * regression: the "Generate report" button sits on the results tab for
	 * every member, the document carries only the aggregate summary they
	 * already read there, and REQ-EVAL-005 names no officer. Report is
	 * therefore deliberately WIDER than close/publish — and this test is what
	 * proves the wider rule is really wired, not just documented.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testReportByPlainBoardMemberSucceeds(): void {
		$this->reportService->expects($this->once())->method('generate')
			->with(evaluationId: self::EVALUATION_ID)
			->willReturn(['path' => 'Decidesk/Board/Evaluations/2026/report.md', 'format' => 'markdown', 'docudesk' => false]);

		$controller = $this->makeController(uid: 'member-uid', bodyMemberUids: ['member-uid']);

		$response = $controller->report(id: self::EVALUATION_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('markdown', $response->getData()['format']);

	}//end testReportByPlainBoardMemberSucceeds()

	/**
	 * ALLOW — the chair generates the report as well; being an officer is not
	 * a way to LOSE the wider permission.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testReportByChairSucceeds(): void {
		$this->reportService->expects($this->once())->method('generate')
			->willReturn(['path' => 'Decidesk/Board/Evaluations/2026/report.md', 'format' => 'markdown', 'docudesk' => false]);

		$controller = $this->makeController(uid: 'chair-uid');

		self::assertSame(
			Http::STATUS_OK,
			$controller->report(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testReportByChairSucceeds()

	/**
	 * DENY — a cycle stored WITHOUT a chair or secretary is closed to every
	 * non-admin. An empty `chairUserId` must not match an empty comparison
	 * value and wave everyone through; OpenRegister's `$userId` match answers
	 * the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function testCloseFailsClosedWhenNoOfficerIsRecorded(): void {
		$this->scoreService->expects($this->never())->method('closeCycle');

		$controller = $this->makeController(
			uid: 'someone',
			evaluation: [
				'governanceBody' => self::BODY_ID,
				'chairUserId' => '',
				'secretaryUserId' => '',
			]
		);

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->close(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testCloseFailsClosedWhenNoOfficerIsRecorded()

	/**
	 * DENY — an evaluation with no resolvable governance body refuses the
	 * report for a non-admin rather than falling open.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function testReportFailsClosedWhenBodyIsUnresolvable(): void {
		$this->reportService->expects($this->never())->method('generate');

		$controller = $this->makeController(
			uid: 'member-uid',
			evaluation: ['chairUserId' => 'chair-uid', 'secretaryUserId' => 'secretary-uid'],
			bodyMemberUids: ['member-uid']
		);

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->report(id: self::EVALUATION_ID)->getStatus()
		);

	}//end testReportFailsClosedWhenBodyIsUnresolvable()
}//end class
