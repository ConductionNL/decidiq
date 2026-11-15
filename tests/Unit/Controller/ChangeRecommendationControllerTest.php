<?php

/**
 * Unit tests for ChangeRecommendationController.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ChangeRecommendationController;
use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;
use OCA\Decidiq\Kernel\Contracts\ChangeRecommendationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionServiceInterface;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Only the chair or secretary of the motion's meeting decides a recommendation.
 *
 * @covers \OCA\Decidiq\Controller\ChangeRecommendationController
 * @uses   \OCA\Decidiq\Service\VotingRoundGuard
 * @uses   \OCA\Decidiq\Exception\ChangeRecommendationRefusedException
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 */
class ChangeRecommendationControllerTest extends TestCase {

	/**
	 * The service.
	 *
	 * @var ChangeRecommendationServiceInterface&MockObject
	 */
	private ChangeRecommendationServiceInterface $service;

	/**
	 * Whether the caller holds a chair or secretary role in meeting-1.
	 *
	 * @var bool
	 */
	private bool $hasRole = true;

	/**
	 * The recommendation service, resolving rec-1 to motion-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(ChangeRecommendationServiceInterface::class);
		$this->service->method('motionIdOf')->willReturnCallback(static fn (string $id): ?string => ($id === 'rec-1' ? 'motion-1' : null));
	}//end setUp()

	/**
	 * A member who is not chair or secretary of the meeting is refused before anything is decided.
	 *
	 * @return void
	 */
	public function testAMemberWithoutTheRoleIsForbidden(): void {
		$this->hasRole = false;
		$this->service->expects($this->never())->method('accept');
		$this->service->expects($this->never())->method('reject');

		$accept = $this->controller(uid: 'raadslid')->accept(id: 'rec-1');
		$reject = $this->controller(uid: 'raadslid')->reject(id: 'rec-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $accept->getStatus());
		$this->assertSame(['message' => 'Chair or secretary role required for this meeting'], $accept->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $reject->getStatus());
	}//end testAMemberWithoutTheRoleIsForbidden()

	/**
	 * Without a user it is 401, and an unknown recommendation is 404.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthorisedAndAnUnknownRecommendationIsNotFound(): void {
		$this->service->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->accept(id: 'rec-1')->getStatus());
		$missing = $this->controller(uid: 'griffier')->accept(id: 'rec-x');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Recommendation not found'], $missing->getData());
	}//end testNoUserIsUnauthorisedAndAnUnknownRecommendationIsNotFound()

	/**
	 * The chair or secretary gets the service's answer, decided in their name.
	 *
	 * @return void
	 */
	public function testTheChairOrSecretaryDecides(): void {
		$this->service->expects($this->once())->method('accept')->with('rec-1', 'griffier')
			->willReturn(['recommendation' => ['status' => 'accepted'], 'motion' => ['text' => 'new']]);
		$this->service->expects($this->once())->method('reject')->with('rec-1', 'griffier')
			->willReturn(['status' => 'rejected']);

		$accept = $this->controller(uid: 'griffier')->accept(id: 'rec-1');
		$reject = $this->controller(uid: 'griffier')->reject(id: 'rec-1');

		$this->assertSame(Http::STATUS_OK, $accept->getStatus());
		$this->assertSame(['recommendation' => ['status' => 'accepted'], 'motion' => ['text' => 'new']], $accept->getData());
		$this->assertSame(['recommendation' => ['status' => 'rejected']], $reject->getData());
	}//end testTheChairOrSecretaryDecides()

	/**
	 * A refusal answers its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatus(): void {
		$this->service->method('accept')->willThrowException(new ChangeRecommendationRefusedException('The passage has changed since this recommendation was made'));
		$this->service->method('reject')->willThrowException(new ChangeRecommendationRefusedException('Motion not found', 404));

		$accept = $this->controller(uid: 'griffier')->accept(id: 'rec-1');
		$reject = $this->controller(uid: 'griffier')->reject(id: 'rec-1');

		$this->assertSame(Http::STATUS_CONFLICT, $accept->getStatus());
		$this->assertSame(['message' => 'The passage has changed since this recommendation was made'], $accept->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $reject->getStatus());
	}//end testARefusalAnswersItsStatus()

	/**
	 * Both routes are open to non-admins, so the role check in the method is what guards them.
	 *
	 * @return void
	 */
	public function testBothRoutesDeclareNoAdminRequired(): void {
		foreach (['accept', 'reject'] as $method) {
			$attributes = (new ReflectionMethod(ChangeRecommendationController::class, $method))->getAttributes(NoAdminRequired::class);
			$this->assertCount(1, $attributes, $method);
		}
	}//end testBothRoutesDeclareNoAdminRequired()

	/**
	 * The controller for this caller, with the real guard over a meeting role answer.
	 *
	 * @param string|null $uid The caller, or null when signed out
	 *
	 * @return ChangeRecommendationController
	 */
	private function controller(?string $uid): ChangeRecommendationController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('hasRole')->willReturnCallback(
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => $meetingId === 'meeting-1' && $roles === ['chair', 'secretary'] && $this->hasRole === true
		);

		$motions = $this->createMock(MotionServiceInterface::class);
		$motions->method('resolveMeetingId')->willReturnCallback(static fn (string $motionId): ?string => ($motionId === 'motion-1' ? 'meeting-1' : null));

		return new ChangeRecommendationController(
			request: $this->createMock(IRequest::class),
			recommendations: $this->service,
			motions: $motions,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $this->createMock(IGroupManager::class),
				appConfig: $this->createMock(IAppConfig::class),
				participantResolver: $participants,
				container: $this->createMock(ContainerInterface::class),
			),
			userSession: $session,
		);
	}//end controller()
}//end class
