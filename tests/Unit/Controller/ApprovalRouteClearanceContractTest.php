<?php

/**
 * Contract test for GET /api/approval-routes/clearance.
 *
 * The endpoint a consuming app asks "may this subject move yet?". It was added
 * by approval-routes-resolve-a-manager-and-declare-silence (REQ-AR-014) as a new
 * public endpoint with no contract test, which gate-25 caught: nothing pinned
 * the SHAPE other apps read, so a rename of `waitingOn` or `cleared` would have
 * broken every consumer silently and this suite would have stayed green.
 *
 * What is pinned here is the contract, not the internals: the status codes, the
 * three keys of the answer, and the fail-closed default that an unfinished route
 * blocks. `cleared` is asserted as an identity (`false`) rather than a negation,
 * because "not empty" would pass on any answer at all.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ApprovalRouteController;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\SubjectClearanceService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The clearance endpoint's wire contract.
 */
class ApprovalRouteClearanceContractTest extends TestCase {
	/**
	 * Build the controller over a store double holding the given stages.
	 *
	 * @param array<int, array<string, mixed>> $stages The subject's stages.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool $authenticated Whether anybody is signed in.
	 *
	 * @return ApprovalRouteController The controller.
	 */
	private function controller(
		array $stages,
		array $params = ['subject' => 'subj-1', 'subjectSchema' => 'decision'],
		bool $authenticated = true,
	): ApprovalRouteController {
		$facade = $this->createMock(ObjectServiceInterface::class);

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'subj-1', 'name' => 'Review']);
		$facade->method('find')->willReturn($entity);
		$facade->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($stages): array {
				if (($config['filters']['schema'] ?? '') === 'decision-stage') {
					return $stages;
				}

				return [];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$store = new RegisterObjectStore($facade);

		return new ApprovalRouteController(
			request: $request,
			service: new ApprovalRouteService(
				store: $store,
				guard: new ApprovalStageGuard(new MandateDirectory($store)),
				mapper: new ApprovalRouteStepMapper(),
			),
			announcer: $this->createMock(ApprovalRouteConclusionAnnouncer::class),
			clearanceService: new SubjectClearanceService(),
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Who is holding up somebody else's file is not public information.
	 *
	 * The least privileged principal that should be refused: no session at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(stages: [], authenticated: false)->clearance();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * The subject and its schema are both required.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function testTheSubjectAndItsSchemaAreRequired(): void {
		$response = $this->controller(stages: [], params: ['subject' => ''])->clearance();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * A subject with a live stage is NOT cleared, and the answer names who is
	 * being waited on, at which step, under which route.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function testAnUnfinishedRouteBlocksAndNamesWhoIsBeingWaitedOn(): void {
		$response = $this->controller(
			stages: [
				[
					'id' => 'stage-1',
					'sequence' => 1,
					'status' => 'active',
					'label' => 'Parafering',
					'assignedPerson' => 'j.jansen',
					'decision' => 'subj-1',
					'route' => 'route-1',
					'dueAt' => '2026-10-01T00:00:00+00:00',
				],
			]
		)->clearance();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		$this->assertFalse($body['cleared'], 'An unfinished route cleared its subject.');
		$this->assertCount(1, $body['waitingOn']);
		$this->assertSame('j.jansen', $body['waitingOn'][0]['actor']);
		$this->assertSame(1, $body['waitingOn'][0]['stage']);
		$this->assertStringContainsString('j.jansen', (string)$body['reason']);
	}

	/**
	 * A subject whose every stage is finished is cleared, and says so in one
	 * line a consumer can show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function testAFinishedRouteClearsItsSubject(): void {
		$response = $this->controller(
			stages: [
				[
					'id' => 'stage-1',
					'sequence' => 1,
					'status' => 'decided',
					'label' => 'Parafering',
					'decision' => 'subj-1',
					'route' => 'route-1',
				],
			]
		)->clearance();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		$this->assertTrue($body['cleared'], 'A finished route did not clear its subject.');
		$this->assertSame([], $body['waitingOn']);
		$this->assertSame('Every required sign-off has been given.', (string)$body['reason']);
	}
}
