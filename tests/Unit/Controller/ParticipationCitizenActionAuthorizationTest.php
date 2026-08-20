<?php

/**
 * Authorization contract for the three authenticated citizen-participation
 * intake endpoints: reaction submission, budget-proposal submission and
 * advisory voting.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ParticipationBudgetController;
use OCA\Decidesk\Controller\ParticipationController;
use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\ParticipationResponder;
use OCA\Decidesk\Service\ParticipationStaffGuard;
use OCA\Decidesk\Service\ReactionIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The three intake endpoints are OPEN to every authenticated account, and the
 * actor identity they record is the SESSION's.
 *
 * Gate-7 (`no-admin-idor`) flagged all three once it stopped accepting a
 * delegated authentication helper as an authorization guard. The conclusion of
 * the investigation was that the OPEN audience is correct — the
 * citizen-participation spec says "Authenticated citizens SHALL submit ..." /
 * "SHALL cast one advisory vote ..." and the register's own baseline says
 * `create: ["authenticated"]` — so this file does NOT assert that some users
 * are refused. Narrowing participation would be the regression, not the fix.
 *
 * What it pins instead is the property that makes an open intake endpoint
 * safe, and both directions of it:
 *
 *   ALLOW  — a signed-in citizen still gets through, and the service is called
 *            with THEIR uid. This is the direction that protects the feature:
 *            a guard that only ever refuses would look identical to a broken
 *            endpoint.
 *   DENY   — with no session the service is NEVER called and the answer is 401.
 *   FORGE  — no routed method takes a submitter/author/voter identity as a
 *            parameter at all, so there is no request-side value that could
 *            reach the stored object. That is asserted by reflection over the
 *            real signatures rather than by a happy-path call, because the
 *            absence of a parameter cannot be observed by exercising one.
 *
 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
 */
class ParticipationCitizenActionAuthorizationTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ReactionIntakeService.
	 *
	 * @var ReactionIntakeService&MockObject
	 */
	private ReactionIntakeService&MockObject $intakeService;

	/**
	 * Mock BudgetVotingService.
	 *
	 * @var BudgetVotingService&MockObject
	 */
	private BudgetVotingService&MockObject $budgetService;

	/**
	 * Mock ParticipationStaffGuard — the only thing stubbed per test is which
	 * uid the SESSION resolves to.
	 *
	 * @var ParticipationStaffGuard&MockObject
	 */
	private ParticipationStaffGuard&MockObject $staffGuard;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->intakeService = $this->createMock(ReactionIntakeService::class);
		$this->budgetService = $this->createMock(BudgetVotingService::class);
		$this->staffGuard = $this->createMock(ParticipationStaffGuard::class);

	}//end setUp()

	/**
	 * Build the consultation/reaction controller over the REAL responder.
	 *
	 * The responder is not mocked: it is the class that decides the
	 * participation status codes, and a stand-in for it would let this suite
	 * pass over a responder that had stopped refusing.
	 *
	 * @return ParticipationController
	 */
	private function participationController(): ParticipationController {
		return new ParticipationController(
			$this->request,
			$this->createMock(ParticipationLifecycleService::class),
			$this->intakeService,
			$this->createMock(ParticipationPublicationService::class),
			new ParticipationResponder($this->staffGuard),
		);

	}//end participationController()

	/**
	 * Build the participatory-budget controller over the REAL responder.
	 *
	 * @return ParticipationBudgetController
	 */
	private function budgetController(): ParticipationBudgetController {
		return new ParticipationBudgetController(
			$this->request,
			$this->createMock(ParticipationLifecycleService::class),
			$this->budgetService,
			$this->createMock(ParticipationPublicationService::class),
			new ParticipationResponder($this->staffGuard),
		);

	}//end budgetController()

	/**
	 * ALLOW — a signed-in citizen submits a reaction, recorded under their uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testSubmitReactionAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid(): void {
		$this->staffGuard->method('currentUid')->willReturn('alice');

		$seen = [];
		$this->intakeService->expects($this->once())
			->method('submitReaction')
			->willReturnCallback(
				function (string $consultationId, string $body, ?string $ncUid, ?string $clientSeed = null) use (&$seen): array {
					$seen = ['consultationId' => $consultationId, 'body' => $body, 'ncUid' => $ncUid];
					return ['id' => 'r-1', 'submitterId' => $ncUid];
				}
			);

		$response = $this->participationController()->submitReaction(consultationId: 'c-1', body: 'Graag meer groen.');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('c-1', $seen['consultationId']);
		self::assertSame(
			'alice',
			$seen['ncUid'],
			'The submitter must be the session uid — the request carries no identity to use instead.'
		);
		self::assertSame(['reaction' => ['id' => 'r-1', 'submitterId' => 'alice']], $response->getData());

	}//end testSubmitReactionAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid()

	/**
	 * DENY — no session: 401 and the intake service is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testSubmitReactionRefusedWithoutASession(): void {
		$this->staffGuard->method('currentUid')->willReturn(null);
		$this->intakeService->expects($this->never())->method('submitReaction');

		$response = $this->participationController()->submitReaction(consultationId: 'c-1', body: 'Graag meer groen.');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSubmitReactionRefusedWithoutASession()

	/**
	 * ALLOW — a signed-in citizen submits a proposal, recorded under their uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testSubmitProposalAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid(): void {
		$this->staffGuard->method('currentUid')->willReturn('bob');

		$seen = [];
		$this->budgetService->expects($this->once())
			->method('submitProposal')
			->willReturnCallback(
				function (
					string $budgetId,
					string $title,
					string $description,
					float $requested,
					string $submitterId,
					string $category = '',
				) use (&$seen): array {
					$seen = ['budgetId' => $budgetId, 'submitterId' => $submitterId];
					return ['id' => 'p-1', 'submitter' => $submitterId, 'status' => 'submitted'];
				}
			);

		$response = $this->budgetController()->submitProposal(
			budgetId: 'b-1',
			title: 'Speeltuin',
			description: 'Opknappen',
			amount: 2000.0
		);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('b-1', $seen['budgetId']);
		self::assertSame('bob', $seen['submitterId'], 'The proposal submitter must be the session uid.');

	}//end testSubmitProposalAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid()

	/**
	 * DENY — no session: 401 and the budget service is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testSubmitProposalRefusedWithoutASession(): void {
		$this->staffGuard->method('currentUid')->willReturn(null);
		$this->budgetService->expects($this->never())->method('submitProposal');

		$response = $this->budgetController()->submitProposal(budgetId: 'b-1', title: 'Speeltuin');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSubmitProposalRefusedWithoutASession()

	/**
	 * ALLOW — a signed-in citizen votes, recorded under their uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testCastAdvisoryVoteAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid(): void {
		$this->staffGuard->method('currentUid')->willReturn('carol');

		$seen = [];
		$this->budgetService->expects($this->once())
			->method('castAdvisoryVote')
			->willReturnCallback(
				function (string $proposalId, string $voterId, string $value) use (&$seen): array {
					$seen = ['proposalId' => $proposalId, 'voterId' => $voterId, 'value' => $value];
					return ['vote' => ['voterId' => $voterId], 'votesFor' => 1, 'votesAgainst' => 0];
				}
			);

		$response = $this->budgetController()->castAdvisoryVote(proposalId: 'p-1', value: 'voor');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('p-1', $seen['proposalId']);
		self::assertSame(
			'carol',
			$seen['voterId'],
			'The voter must be the session uid — otherwise one caller could vote as another citizen '
				. 'and the one-vote-per-citizen rule would be trivially defeated.'
		);

	}//end testCastAdvisoryVoteAllowedForAnyAuthenticatedUserAndRecordsTheSessionUid()

	/**
	 * DENY — no session: 401 and the budget service is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testCastAdvisoryVoteRefusedWithoutASession(): void {
		$this->staffGuard->method('currentUid')->willReturn(null);
		$this->budgetService->expects($this->never())->method('castAdvisoryVote');

		$response = $this->budgetController()->castAdvisoryVote(proposalId: 'p-1', value: 'voor');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCastAdvisoryVoteRefusedWithoutASession()

	/**
	 * FORGE — none of the three routed methods accepts an actor identity.
	 *
	 * The stored `submitterId` / `submitter` / `voterId` can only come from the
	 * session because there is no parameter through which a request could offer
	 * an alternative. Asserted over the real signatures: an absent parameter is
	 * not something a happy-path call can demonstrate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function testNoIntakeEndpointAcceptsAnActorIdentityParameter(): void {
		$routed = [
			[ParticipationController::class, 'submitReaction'],
			[ParticipationBudgetController::class, 'submitProposal'],
			[ParticipationBudgetController::class, 'castAdvisoryVote'],
		];

		foreach ($routed as [$class, $method]) {
			$names = array_map(
				static fn (\ReflectionParameter $p): string => $p->getName(),
				(new ReflectionMethod($class, $method))->getParameters()
			);

			// Sanity: the reflection actually found a signature, so an empty
			// list below cannot be an artefact of looking at nothing.
			self::assertNotEmpty($names, sprintf('%s::%s() must declare parameters.', $class, $method));

			foreach ($names as $name) {
				self::assertDoesNotMatchRegularExpression(
					'/submitter|author|voter|(^|[^a-z])uid|userid|ncuid/i',
					$name,
					sprintf(
						'%s::%s() must not accept `%s` — an actor identity taken from the request could be '
							. 'forged, and the session already supplies it.',
						$class,
						$method,
						$name
					)
				);
			}
		}

	}//end testNoIntakeEndpointAcceptsAnActorIdentityParameter()
}//end class
