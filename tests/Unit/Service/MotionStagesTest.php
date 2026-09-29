<?php

/**
 * Unit tests for the motion stage steps (mot-08) and the theme fragment (mot-15).
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
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\MotionController;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\MotionStages;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Anna submitted motion M-12; Piet chairs its meeting; Kees is a member.
 *
 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
 */
class MotionStagesTest extends TestCase {

	/**
	 * The stored motion.
	 *
	 * @var array<string, mixed>
	 */
	private array $motion = ['title' => 'M-12', 'decisionType' => 'motion', 'lifecycle' => 'proposed'];

	/**
	 * Build the real MotionStages over an object service holding M-12
	 * (owner anna) in meeting meeting-1 chaired by piet.
	 *
	 * @param MotionService|null $motionService The motion service to use, or a double resolving meeting-1
	 *
	 * @return MotionStages
	 */
	private function stages(?MotionService $motionService=null): MotionStages {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if ($id !== 'm-12') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('m-12');
				$entity->setOwner('anna');
				$entity->setObject($this->motion);
				return $entity;
			}
		);

		if ($motionService === null) {
			$motionService = $this->createMock(MotionService::class);
			$motionService->method('resolveMeetingId')->willReturn('meeting-1');
		}

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturnCallback(
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => $meetingId === 'meeting-1' && $nextcloudUid === 'piet'
		);

		return new MotionStages(
			objectService: $objectService,
			motionService: $motionService,
			participantResolver: $resolver,
			groupManager: $this->createMock(IGroupManager::class),
			appConfig: $this->createMock(IAppConfig::class),
		);
	}//end stages()

	/**
	 * The controller over the real MotionStages, with $uid signed in.
	 *
	 * @param string        $uid           The caller
	 * @param MotionService $motionService The motion service
	 * @param array         $params        The request parameters
	 *
	 * @return MotionController
	 */
	private function controller(string $uid, MotionService $motionService, array $params=[]): MotionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturnCallback(
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => $nextcloudUid === 'piet'
		);

		return new MotionController(
			request: $request,
			motionService: $motionService,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			appConfig: $this->createMock(IAppConfig::class),
			participantResolver: $resolver,
			motionStages: $this->stages(motionService: $motionService),
		);
	}//end controller()

	/**
	 * A motion service double that resolves meeting-1.
	 *
	 * @return MotionService
	 */
	private function motionService(): MotionService {
		$motionService = $this->createMock(MotionService::class);
		$motionService->method('resolveMeetingId')->willReturn('meeting-1');
		return $motionService;
	}//end motionService()

	/**
	 * The chair gets every step of the motion lifecycle, with Decided split
	 * into adopted and rejected.
	 *
	 * @return void
	 */
	public function testTheChairGetsEveryStepWithBothResults(): void {
		$this->assertSame(
			[['to' => 'decided', 'outcome' => 'adopted'], ['to' => 'decided', 'outcome' => 'rejected'], ['to' => 'withdrawn']],
			MotionStages::actionsFor(lifecycle: 'voting', canManage: true, isSubmitter: false)
		);
	}//end testTheChairGetsEveryStepWithBothResults()

	/**
	 * The submitter may only withdraw, and only while withdrawal is a step.
	 *
	 * @return void
	 */
	public function testTheSubmitterMayOnlyWithdraw(): void {
		$this->assertSame([['to' => 'withdrawn']], MotionStages::actionsFor(lifecycle: 'proposed', canManage: false, isSubmitter: true));
		$this->assertSame([], MotionStages::actionsFor(lifecycle: 'enacted', canManage: false, isSubmitter: true));
		$this->assertSame([], MotionStages::actionsFor(lifecycle: 'proposed', canManage: false, isSubmitter: false));
	}//end testTheSubmitterMayOnlyWithdraw()

	/**
	 * Anna, who submitted M-12, is offered Withdraw; Kees nothing; Piet the
	 * chair the next stage and Withdraw.
	 *
	 * @return void
	 */
	public function testEachCallerIsOfferedTheirOwnSteps(): void {
		$stages = $this->stages();

		$this->assertSame([['to' => 'withdrawn']], $stages->forCaller(motionId: 'm-12', uid: 'anna')['actions']);
		$this->assertSame([], $stages->forCaller(motionId: 'm-12', uid: 'kees')['actions']);
		$this->assertSame(
			[['to' => 'deliberating'], ['to' => 'withdrawn']],
			$stages->forCaller(motionId: 'm-12', uid: 'piet')['actions']
		);
		$this->assertSame('proposed', $stages->forCaller(motionId: 'm-12', uid: 'kees')['lifecycle']);
		$this->assertNull($stages->forCaller(motionId: 'no-such', uid: 'anna'));
	}//end testEachCallerIsOfferedTheirOwnSteps()

	/**
	 * GET /api/motions/{id}/transitions answers the caller's steps, 404 for
	 * a motion that cannot be read.
	 *
	 * @return void
	 */
	public function testTheTransitionsRouteAnswersTheCallersSteps(): void {
		$response = $this->controller(uid: 'anna', motionService: $this->motionService())->transitions(id: 'm-12');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['to' => 'withdrawn']], $response->getData()['actions']);

		$missing = $this->controller(uid: 'anna', motionService: $this->motionService())->transitions(id: 'no-such');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

		$method = new \ReflectionMethod(MotionController::class, 'transitions');
		$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class));

		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$this->assertContains(
			['name' => 'motion#transitions', 'url' => '/api/motions/{id}/transitions', 'verb' => 'GET'],
			$routes
		);
	}//end testTheTransitionsRouteAnswersTheCallersSteps()

	/**
	 * Scenario "A member withdraws her motion": Anna is no chair, yet her
	 * Withdraw goes through the guarded transition.
	 *
	 * @return void
	 */
	public function testTheSubmitterWithdrawsHerMotion(): void {
		$motionService = $this->motionService();
		$motionService->expects($this->once())->method('transitionLifecycle')->with(
			'm-12',
			'motion',
			'withdrawn',
			'anna',
			null
		);

		$response = $this->controller(uid: 'anna', motionService: $motionService, params: ['newState' => 'withdrawn'])->transition(id: 'm-12');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testTheSubmitterWithdrawsHerMotion()

	/**
	 * The submitter may not take any other step, and another member may not
	 * withdraw someone else's motion.
	 *
	 * @return void
	 */
	public function testNoOtherStepForTheSubmitterAndNoWithdrawForOthers(): void {
		$motionService = $this->motionService();
		$motionService->expects($this->never())->method('transitionLifecycle');

		$advance = $this->controller(uid: 'anna', motionService: $motionService, params: ['newState' => 'deliberating'])->transition(id: 'm-12');
		$this->assertSame(Http::STATUS_FORBIDDEN, $advance->getStatus());

		$other = $this->controller(uid: 'kees', motionService: $motionService, params: ['newState' => 'withdrawn'])->transition(id: 'm-12');
		$this->assertSame(Http::STATUS_FORBIDDEN, $other->getStatus());
	}//end testNoOtherStepForTheSubmitterAndNoWithdrawForOthers()

	/**
	 * The merged Decision and Theme schemas (base register plus every
	 * register.d fragment), keyed by slug.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * Validate an object against a merged schema's properties.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return bool
	 */
	private function validates(array $data, array $schema): bool {
		$result = (new \Opis\JsonSchema\Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode(
				(string)json_encode(
					['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $schema['properties'], 'additionalProperties' => false]
				)
			)
		);
		return $result->isValid();
	}//end validates()

	/**
	 * Scenario "Filter on a theme": a theme validates, a motion tagged with
	 * theme names validates, and themes, stage and result are facets the
	 * motions list filters on.
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-002-tag-motions-by-theme-and-filter
	 *
	 * @return void
	 */
	public function testThemesAreAListAndAMotionFacet(): void {
		$theme = $this->mergedSchema(slug: 'theme');
		$this->assertNotSame([], $theme, 'A theme schema is declared');
		$this->assertTrue($this->validates(['name' => 'Housing'], $theme));
		$this->assertFalse($this->validates(['description' => 'no name'], $theme));

		$decision = $this->mergedSchema(slug: 'decision');
		$this->assertTrue(
			$this->validates(['title' => 'M-12', 'decisionType' => 'motion', 'themes' => ['Housing', 'Climate']], ['properties' => $decision['properties']])
		);
		foreach (['themes', 'lifecycle', 'outcome'] as $facet) {
			$this->assertTrue(($decision['properties'][$facet]['facetable'] ?? false), "$facet is a facet");
		}

		$registerSchemas = [];
		foreach ((glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$registerSchemas = array_merge($registerSchemas, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		$this->assertContains('theme', $registerSchemas);
	}//end testThemesAreAListAndAMotionFacet()
}//end class
