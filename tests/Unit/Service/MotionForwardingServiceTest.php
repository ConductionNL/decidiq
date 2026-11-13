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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

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
 * @covers \OCA\Decidiq\Service\MotionForwardingService
 * @spec   openspec/specs/motion-amendment/spec.md
 */
class MotionForwardingServiceTest extends TestCase {

	/**
	 * Captured saveObject() calls, in order.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>
	 */
	private \ArrayObject $saves;

	/**
	 * The notifier double.
	 *
	 * @var MotionNotifier&MockObject
	 */
	private MotionNotifier $notifier;

	/**
	 * Build the service.
	 *
	 * @param array<string, array<string, mixed>> $store            Objects keyed by id
	 * @param bool                                $actorExists      Whether the user manager knows the actor
	 * @param string                              $requiresApproval Stored motion_forwarding_requires_approval value
	 *
	 * @return MotionForwardingService
	 */
	private function buildService(array $store, bool $actorExists = true, string $requiresApproval = 'false'): MotionForwardingService {
		$this->saves = new \ArrayObject();
		$saves = $this->saves;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($store[(string)$id]) === true ? $this->entity($store[(string)$id]) : null
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($saves): ObjectEntity {
				$saves->append(['uuid' => $uuid, 'object' => $object, 'register' => $register, 'schema' => $schema]);
				if ($uuid === null) {
					$object['id'] = 'forwarded-' . count($saves);
				}

				return $this->entity($object);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string =>
				($app === 'decidiq' && $key === 'motion_forwarding_requires_approval') ? $requiresApproval : $default
		);

		$this->notifier = $this->createMock(MotionNotifier::class);
		$notifier = $this->notifier;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				IAppConfig::class => $appConfig,
				MotionNotifier::class => $notifier,
				default => throw new RuntimeException('not wired in test: ' . $id),
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($actorExists === true ? $this->createMock(IUser::class) : null);

		return new MotionForwardingService(
			container: $container,
			userManager: $userManager,
			objectService: $objectService,
		);

	}//end buildService()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
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
	 * The source motion.
	 *
	 * @return array<string, mixed>
	 */
	private static function motion(): array {
		return [
			'id' => 'm1',
			'decisionType' => 'motion',
			'title' => 'Motie Groen',
			'text' => 'Meer bomen.',
			'motionType' => 'resolution',
			'proposer' => 'alice',
			'coSigners' => ['bob'],
			'lifecycle' => 'adopted',
			'notes' => [['title' => 'Oud', 'body' => 'x']],
		];

	}//end motion()

	public function testRefusesAnUnknownActor(): void {
		$service = $this->buildService(store: ['m1' => self::motion()], actorExists: false);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Actor ghost not found');
		$service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'ghost', justification: 'j');

	}//end testRefusesAnUnknownActor()

	public function testRefusesAMissingMotion(): void {
		$service = $this->buildService(store: []);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion m1 not found');
		$service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'chair', justification: 'j');

	}//end testRefusesAMissingMotion()

	public function testRefusesADecisionThatIsNotAMotion(): void {
		$service = $this->buildService(store: ['m1' => array_merge(self::motion(), ['decisionType' => 'amendment'])]);

		try {
			$service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'chair', justification: 'j');
			$this->fail('expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('Motion m1 not found', $e->getMessage());
		}

		$this->assertCount(0, $this->saves);

	}//end testRefusesADecisionThatIsNotAMotion()

	public function testCreatesAProposedCopyLinkedToTheTargetBodyAndTheSource(): void {
		$service = $this->buildService(store: ['m1' => self::motion()]);
		$this->notifier->expects($this->never())->method('notify');

		$created = $service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'chair', justification: 'Bevoegd orgaan');

		$this->assertSame('forwarded-1', $created['id']);
		$this->assertCount(2, $this->saves);

		$copy = $this->saves[0];
		$this->assertNull($copy['uuid']);
		$this->assertSame('decidiq', $copy['register']);
		$this->assertSame('decision', $copy['schema']);
		$this->assertSame('motion', $copy['object']['decisionType']);
		$this->assertSame('proposed', $copy['object']['lifecycle']);
		$this->assertSame('Motie Groen', $copy['object']['title']);
		$this->assertSame('Meer bomen.', $copy['object']['text']);
		$this->assertSame('resolution', $copy['object']['motionType']);
		$this->assertSame('alice', $copy['object']['proposer']);
		$this->assertSame(['bob'], $copy['object']['coSigners']);
		$this->assertSame(
			[
				['register' => 'decidiq', 'schema' => 'governance-body', 'id' => 'body-2'],
				['register' => 'decidiq', 'schema' => 'decision', 'id' => 'm1'],
			],
			$copy['object']['relations']
		);
		$this->assertSame('Doorgestuurd van', $copy['object']['notes'][0]['title']);
		$note = json_decode($copy['object']['notes'][0]['body'], true);
		$this->assertSame('m1', $note['sourceMotionId']);
		$this->assertSame('body-2', $note['targetBodyId']);
		$this->assertSame('chair', $note['forwardedBy']);
		$this->assertSame('Bevoegd orgaan', $note['justification']);

	}//end testCreatesAProposedCopyLinkedToTheTargetBodyAndTheSource()

	public function testAppendsAForwardingNoteToTheSourceMotion(): void {
		$service = $this->buildService(store: ['m1' => self::motion()]);
		$service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'chair', justification: 'j');

		$source = $this->saves[1];
		$this->assertSame('m1', $source['uuid']);
		$this->assertSame('adopted', $source['object']['lifecycle']);
		$this->assertCount(2, $source['object']['notes']);
		$this->assertSame('Oud', $source['object']['notes'][0]['title']);
		$this->assertSame('Doorgestuurd naar', $source['object']['notes'][1]['title']);
		$note = json_decode($source['object']['notes'][1]['body'], true);
		$this->assertSame('body-2', $note['targetBodyId']);
		$this->assertSame('forwarded-1', $note['forwardedMotionId']);

	}//end testAppendsAForwardingNoteToTheSourceMotion()

	public function testDefaultsMissingSourceFields(): void {
		$service = $this->buildService(store: ['m1' => ['id' => 'm1', 'decisionType' => 'motion']]);
		$service->forward(motionId: 'm1', targetBodyId: 'b', actorId: 'chair', justification: 'j');

		$copy = $this->saves[0]['object'];
		$this->assertSame('', $copy['title']);
		$this->assertSame('', $copy['text']);
		$this->assertSame('motion', $copy['motionType']);
		$this->assertSame('', $copy['proposer']);
		$this->assertSame([], $copy['coSigners']);

	}//end testDefaultsMissingSourceFields()

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function approvalSettings(): array {
		return [
			'true' => ['true', true],
			'padded upper TRUE' => [' TRUE ', true],
			'one' => ['1', true],
			'false' => ['false', false],
			'yes is not a truthy spelling' => ['yes', false],
			'empty' => ['', false],
		];

	}//end approvalSettings()

	/**
	 * @dataProvider approvalSettings
	 */
	public function testNotifiesOnlyWhenApprovalIsRequired(string $setting, bool $expectNotify): void {
		$service = $this->buildService(store: ['m1' => self::motion()], requiresApproval: $setting);

		if ($expectNotify === true) {
			$this->notifier->expects($this->once())->method('notify')->with(
				'chair',
				'forwarded-1',
				'motion_forwarded_approval',
				['title' => 'Motie Groen', 'body' => 'body-2'],
				'Decidiq: notification send failed: '
			);
		} else {
			$this->notifier->expects($this->never())->method('notify');
		}

		$service->forward(motionId: 'm1', targetBodyId: 'body-2', actorId: 'chair', justification: 'j');

	}//end testNotifiesOnlyWhenApprovalIsRequired()
}//end class
