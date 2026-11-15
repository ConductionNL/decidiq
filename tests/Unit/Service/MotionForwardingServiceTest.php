<?php

/**
 * Unit tests for MotionForwardingService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionForwardingService;
use OCA\Decidiq\Service\MotionNotifier;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Forwarding a motion to another governance body.
 *
 * @covers \OCA\Decidiq\Service\MotionForwardingService
 */
class MotionForwardingServiceTest extends TestCase {

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock motion notifier.
	 *
	 * @var MotionNotifier&MockObject
	 */
	private MotionNotifier&MockObject $notifier;

	/**
	 * Captured saveObject() calls.
	 *
	 * @var list<array{object: array<string, mixed>, register: mixed, schema: mixed, uuid: string|null}>
	 */
	private array $saves = [];

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notifier = $this->createMock(MotionNotifier::class);

		$this->saves = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			): ObjectEntity {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($uuid === null) {
					$object['id'] = 'forwarded-1';
				}

				return $this->entity($object);
			}
		);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return MotionForwardingService
	 */
	private function service(): MotionForwardingService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				IAppConfig::class => $this->appConfig,
				MotionNotifier::class => $this->notifier,
				default => throw new RuntimeException('not wired: ' . $id),
			}
		);

		return new MotionForwardingService(
			container: $container,
			userManager: $this->userManager,
			objectService: $this->objectService,
		);

	}//end service()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Make the actor exist and the source motion resolvable.
	 *
	 * @param array<string, mixed>|null $motion The source payload, null for not found
	 * @param string $requiresApproval The stored approval setting
	 *
	 * @return void
	 */
	private function seed(?array $motion, string $requiresApproval = 'false'): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$this->objectService->method('find')->with('motion-1')->willReturn(
			($motion === null) ? null : $this->entity($motion)
		);
		$this->appConfig->method('getValueString')
			->with('decidiq', 'motion_forwarding_requires_approval', 'false')
			->willReturn($requiresApproval);

	}//end seed()

	/**
	 * A motion-typed source decision.
	 *
	 * @return array<string, mixed>
	 */
	private static function motion(): array {
		return [
			'id' => 'motion-1',
			'decisionType' => 'motion',
			'title' => 'Motie groen',
			'text' => 'Meer bomen.',
			'motionType' => 'resolution',
			'proposer' => 'bob',
			'coSigners' => ['carol'],
			'lifecycle' => 'adopted',
			'notes' => [['title' => 'Bestaand', 'body' => 'x']],
		];

	}//end motion()

	/**
	 * An unknown actor is refused before anything is read.
	 *
	 * @return void
	 */
	public function testUnknownActorIsRefused(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Actor ghost not found');

		$this->service()->forward('motion-1', 'body-2', 'ghost', 'reason');

	}//end testUnknownActorIsRefused()

	/**
	 * A missing source motion is refused.
	 *
	 * @return void
	 */
	public function testMissingMotionIsRefused(): void {
		$this->seed(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion motion-1 not found');

		$this->service()->forward('motion-1', 'body-2', 'alice', 'reason');

	}//end testMissingMotionIsRefused()

	/**
	 * A decision that is not a motion is refused as not found.
	 *
	 * @return void
	 */
	public function testNonMotionDecisionIsRefused(): void {
		$this->seed(['id' => 'motion-1', 'decisionType' => 'meeting-outcome']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion motion-1 not found');

		$this->service()->forward('motion-1', 'body-2', 'alice', 'reason');

	}//end testNonMotionDecisionIsRefused()

	/**
	 * Forwarding creates a proposed motion copy and notes the forwarding on the source.
	 *
	 * @return void
	 */
	public function testForwardCreatesCopyAndNotesSource(): void {
		$this->seed(self::motion());
		$this->notifier->expects($this->never())->method('notify');

		$created = $this->service()->forward('motion-1', 'body-2', 'alice', 'Bevoegd orgaan');

		$this->assertSame('forwarded-1', $created['id']);
		$this->assertCount(2, $this->saves);

		$copy = $this->saves[0];
		$this->assertNull($copy['uuid']);
		$this->assertSame('decidiq', $copy['register']);
		$this->assertSame('decision', $copy['schema']);
		$this->assertSame('motion', $copy['object']['decisionType']);
		$this->assertSame('Motie groen', $copy['object']['title']);
		$this->assertSame('Meer bomen.', $copy['object']['text']);
		$this->assertSame('resolution', $copy['object']['motionType']);
		$this->assertSame('bob', $copy['object']['proposer']);
		$this->assertSame(['carol'], $copy['object']['coSigners']);
		$this->assertSame('proposed', $copy['object']['lifecycle']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $copy['object']['submittedAt']);
		$this->assertSame(
			[
				['register' => 'decidiq', 'schema' => 'governance-body', 'id' => 'body-2'],
				['register' => 'decidiq', 'schema' => 'decision', 'id' => 'motion-1'],
			],
			$copy['object']['relations']
		);
		$this->assertCount(1, $copy['object']['notes']);
		$this->assertSame('Doorgestuurd van', $copy['object']['notes'][0]['title']);
		$fromNote = json_decode($copy['object']['notes'][0]['body'], true);
		$this->assertSame('motion-1', $fromNote['sourceMotionId']);
		$this->assertSame('body-2', $fromNote['targetBodyId']);
		$this->assertSame('alice', $fromNote['forwardedBy']);
		$this->assertSame('Bevoegd orgaan', $fromNote['justification']);
		$this->assertArrayHasKey('forwardedAt', $fromNote);

		$source = $this->saves[1];
		$this->assertSame('motion-1', $source['uuid']);
		$this->assertSame('decidiq', $source['register']);
		$this->assertSame('decision', $source['schema']);
		$this->assertSame('adopted', $source['object']['lifecycle']);
		$this->assertCount(2, $source['object']['notes']);
		$this->assertSame(['title' => 'Bestaand', 'body' => 'x'], $source['object']['notes'][0]);
		$this->assertSame('Doorgestuurd naar', $source['object']['notes'][1]['title']);
		$toNote = json_decode($source['object']['notes'][1]['body'], true);
		$this->assertSame('body-2', $toNote['targetBodyId']);
		$this->assertSame('forwarded-1', $toNote['forwardedMotionId']);

	}//end testForwardCreatesCopyAndNotesSource()

	/**
	 * Missing source fields fall back to the documented defaults.
	 *
	 * @return void
	 */
	public function testSparseSourceUsesDefaults(): void {
		$this->seed(['id' => 'motion-1', 'decisionType' => 'motion']);

		$this->service()->forward('motion-1', 'body-2', 'alice', '');

		$copy = $this->saves[0]['object'];
		$this->assertSame('', $copy['title']);
		$this->assertSame('', $copy['text']);
		$this->assertSame('motion', $copy['motionType']);
		$this->assertSame('', $copy['proposer']);
		$this->assertSame([], $copy['coSigners']);
		$this->assertCount(1, $this->saves[1]['object']['notes']);

	}//end testSparseSourceUsesDefaults()

	/**
	 * When approval is required the actor is notified about the forwarded copy.
	 *
	 * @return void
	 */
	public function testApprovalRequiredNotifiesActor(): void {
		$this->seed(self::motion(), ' TRUE ');
		$this->notifier->expects($this->once())
			->method('notify')
			->with(
				'alice',
				'forwarded-1',
				'motion_forwarded_approval',
				['title' => 'Motie groen', 'body' => 'body-2'],
				'Decidiq: notification send failed: '
			);

		$this->service()->forward('motion-1', 'body-2', 'alice', 'reason');

	}//end testApprovalRequiredNotifiesActor()

	/**
	 * The stored value "1" also counts as requiring approval.
	 *
	 * @return void
	 */
	public function testApprovalFlagOneNotifies(): void {
		$this->seed(self::motion(), '1');
		$this->notifier->expects($this->once())->method('notify');

		$this->service()->forward('motion-1', 'body-2', 'alice', 'reason');

	}//end testApprovalFlagOneNotifies()

	/**
	 * Any other stored value does not notify.
	 *
	 * @return void
	 */
	public function testApprovalFlagOtherValueDoesNotNotify(): void {
		$this->seed(self::motion(), 'yes');
		$this->notifier->expects($this->never())->method('notify');

		$this->service()->forward('motion-1', 'body-2', 'alice', 'reason');

	}//end testApprovalFlagOtherValueDoesNotNotify()
}//end class
