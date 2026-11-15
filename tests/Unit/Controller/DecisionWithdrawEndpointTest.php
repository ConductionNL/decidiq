<?php

/**
 * decision#withdraw: an administrator withdraws a taken decision; the original
 * outcome stays readable beside the withdrawal, and a withdrawal that names no
 * actor kind is refused before anything is written (REQ-DWP-005).
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DecisionController;
use OCA\Decidiq\Service\DecisionLifecycleService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\DecisionController::withdraw
 * @covers \OCA\Decidiq\Service\DecisionWithdrawalRecorder
 * @uses   \OCA\Decidiq\Controller\DecisionController::__construct
 * @uses   \OCA\Decidiq\Service\DecisionWithdrawalService
 * @uses   \OCA\Decidiq\Service\DecisionPublicationService
 */
class DecisionWithdrawEndpointTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private ObjectServiceInterface&MockObject $objects;

	private IGroupManager&MockObject $groups;

	private IUserSession&MockObject $session;

	/**
	 * Every saveObject() call: object, schema and uuid.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}

	/**
	 * The controller, with request parameters.
	 *
	 * @param array<string, string> $params The body.
	 *
	 * @return DecisionController
	 */
	private function controller(array $params): DecisionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new DecisionController(
			request: $request,
			container: $this->container,
			userSession: $this->session,
			groupManager: $this->groups,
			logger: $this->createMock(LoggerInterface::class),
			lifecycleService: $this->createMock(DecisionLifecycleService::class),
		);
	}

	/**
	 * An administrator, and a stored decision.
	 *
	 * @param array<string, mixed>|null $decision The stored decision, or null for none.
	 *
	 * @return void
	 */
	private function adminWith(?array $decision): void {
		$this->groups->method('isAdmin')->with('admin')->willReturn(true);
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		if ($decision === null) {
			$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
			return;
		}

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($decision);
		$this->objects->method('find')->willReturn($entity);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				$saved = $this->createMock(ObjectEntity::class);
				$saved->method('getObject')->willReturn($object);
				return $saved;
			}
		);
	}

	public function testAnInterestedPartyWithdrawsAndTheOutcomeStaysReadable(): void {
		$this->adminWith(['id' => 'd1', 'outcome' => 'granted', 'history' => [['event' => 'adopted']]]);

		$response = $this->controller(['withdrawnBy' => 'belanghebbende', 'reason' => ' Aanvraag ingetrokken '])->withdraw('d1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->saves);
		$this->assertSame(['register' => 'decidiq', 'schema' => 'decision', 'uuid' => 'd1'], array_intersect_key($this->saves[0], array_flip(['register', 'schema', 'uuid'])));
		$data = $response->getData();
		$this->assertSame('granted', $data['outcome']);
		$this->assertTrue($data['withdrawn']);
		$this->assertSame('belanghebbende', $data['withdrawnBy']);
		$this->assertSame('Aanvraag ingetrokken', $data['withdrawnReason']);
		$this->assertSame(['adopted', 'withdrawn'], array_column($data['history'], 'event'));
		$this->assertSame('granted', $data['history'][1]['outcomeAtWithdrawal']);
	}

	public function testAWithdrawalWithoutAnActorKindIsRefusedAndNothingIsWritten(): void {
		$this->adminWith(['id' => 'd1', 'outcome' => 'granted']);

		$response = $this->controller(['reason' => 'x'])->withdraw('d1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('bestuursorgaan or belanghebbende', $response->getData()['message']);
		$this->assertSame([], $this->saves);
	}

	public function testAnAlreadyWithdrawnDecisionIsRefused(): void {
		$this->adminWith(['id' => 'd1', 'outcome' => 'granted', 'withdrawn' => true]);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());
		$this->assertSame([], $this->saves);
	}

	public function testAnUnknownDecisionIs404(): void {
		$this->adminWith(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('nope')->getStatus());
	}

	public function testANonAdministratorOrNoSessionWithdrawsNothing(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->container->expects($this->never())->method('get');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());

		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());
	}

	public function testWithoutOpenRegisterOrWhenTheSaveFailsTheAnswerSaysSo(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->container->method('get')->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('absent')), $this->objects);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['outcome' => 'granted']);
		$this->objects->method('find')->willReturn($entity);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$response = $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'The withdrawal could not be saved.'], $response->getData());
	}
}
