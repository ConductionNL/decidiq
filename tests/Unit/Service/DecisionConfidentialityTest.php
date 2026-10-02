<?php

/**
 * Refusal tests: a decision under a confidentiality restriction is never published.
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

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Service\AgendaPapers;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\ConfidentialityRestrictions;
use OCA\Decidiq\Service\DecisionPublicationService;
use OCA\Decidiq\Service\LegalRemedyResolver;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\Decidiq\Tests\Unit\Support\RegisterScopedObjectServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Confidentiality of a decision lives in confidentiality-restriction objects
 * (scope decision, targetDecision, lifecycle imposed / ratified / dissolved).
 * Both publication paths read them before writing anything: the catalogue
 * publication and the decision's own publish action.
 *
 * @covers \OCA\Decidiq\Service\ConfidentialityRestrictions
 * @covers \OCA\Decidiq\Service\PublicationEligibilityService
 * @covers \OCA\Decidiq\Service\PublicationService
 * @covers \OCA\Decidiq\Service\DecisionPublicationService
 * @uses   \OCA\Decidiq\Service\AgendaPapers
 * @uses   \OCA\Decidiq\Service\LegalRemedyResolver
 * @uses   \OCA\Decidiq\Service\PublicationConfigService
 * @uses   \OCA\Decidiq\Service\PublicationPayloadService
 * @uses   \OCA\Decidiq\Service\PublicationRepository
 * @uses   \OCA\Decidiq\Service\Records\SecurityClassification
 * @uses   \OCA\Decidiq\Service\SettingsService
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-a-decision-under-a-confidentiality-restriction-is-never-published
 */
class DecisionConfidentialityTest extends TestCase {

	/**
	 * Objects in the register, by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $store = [];

	/**
	 * Every object written through the object service.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $written = [];

	/**
	 * Everything the catalogue publisher was handed, JSON encoded.
	 *
	 * @var list<string>
	 */
	private array $catalogued = [];

	/**
	 * The land purchase decision the council took behind closed doors.
	 *
	 * @return array<string,mixed>
	 */
	private function decision(): array {
		return [
			'id' => 'decision-1',
			'title' => 'Land purchase Noordkade',
			'lifecycle' => 'decided',
			'outcome' => 'adopted',
			'isPublished' => 'internal',
			'governanceBody' => 'body-1',
			'bodyName' => 'Municipal council',
		];
	}//end decision()

	/**
	 * An entity double serializing to $data.
	 *
	 * @param array<string,mixed> $data The object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The object service over the register, answering the restrictions.
	 *
	 * @param list<array<string,mixed>>|null $restrictions The restrictions, null when reading them fails
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function objects(?array $restrictions): ObjectServiceInterface&MockObject {
		$this->store = ['decision-1' => $this->decision()];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($restrictions): array {
				if (($config['filters']['schema'] ?? null) !== 'confidentiality-restriction') {
					return [];
				}

				if ($restrictions === null) {
					throw new RuntimeException('database gone');
				}

				return array_map(fn (array $r) => $this->entity($r), $restrictions);
			}
		);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?object => isset($this->store[(string)$id]) === true ? $this->entity($this->store[(string)$id]) : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): object {
				$uuid = ($uuid ?? ('obj-' . (count($this->written) + 1)));
				$this->written[] = array_merge(['id' => $uuid, '_schema' => $schema], $object);
				$this->store[$uuid] = end($this->written);
				return $this->entity($this->store[$uuid]);
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The catalogue publication path, with the real eligibility and payload services.
	 *
	 * @param list<array<string,mixed>>|null $restrictions The restrictions, null when reading them fails
	 *
	 * @return PublicationService
	 */
	private function catalogue(?array $restrictions): PublicationService {
		$objects = $this->objects($restrictions);
		$logger  = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"body-1":{"catalog":"council-catalog"}}');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$publisher = $this->createMock(OpenCatalogiPublisher::class);
		$publisher->method('publish')->willReturnCallback(
			function (string $catalog, string $payloadId, array $payloadData): string {
				$this->catalogued[] = (string)json_encode([$catalog, $payloadId, $payloadData]);
				return 'catalog-publication-1';
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

		return new PublicationService(
			$logger,
			$appManager,
			new PublicationEligibilityService($logger, $objects),
			new PublicationPayloadService($container, $logger, new PublicationConfigService($appConfig), new AgendaPapers($objects, $container, $logger)),
			new PublicationConfigService($appConfig),
			$publisher,
			$audit,
			$objects,
			eventRecorder: $this->createMock(\OCA\Decidiq\Service\PublicationEventRecorder::class),
		);
	}//end catalogue()

	/**
	 * The decision's own publish action.
	 *
	 * @param list<array<string,mixed>>|null $restrictions The restrictions, null when reading them fails
	 *
	 * @return array{0: DecisionPublicationService, 1: RegisterScopedObjectServiceFake}
	 */
	private function direct(?array $restrictions): array {
		$register  = new RegisterScopedObjectServiceFake(rows: ['decision' => ['decision-1' => $this->decision()]]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($register);

		$service = new DecisionPublicationService(
			$container,
			$this->createMock(LoggerInterface::class),
			new LegalRemedyResolver(),
			new ConfidentialityRestrictions($this->objects($restrictions)),
		);

		return [$service, $register];
	}//end direct()

	/**
	 * A restriction on decision-1 in the given state.
	 *
	 * @param string $state The restriction lifecycle
	 *
	 * @return array<string,mixed>
	 */
	private static function restriction(string $state): array {
		return ['scope' => 'decision', 'targetDecision' => 'decision-1', 'lifecycle' => $state];
	}//end restriction()

	/**
	 * The restriction states that keep a decision out.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function activeStates(): array {
		return ['imposed' => ['imposed'], 'ratified' => ['ratified']];
	}//end activeStates()

	/**
	 * A decision under an imposed or ratified restriction is refused before
	 * anything is written: no payload, no record, no catalogue publication, no
	 * published flag on the decision.
	 *
	 * @param string $state The restriction lifecycle
	 *
	 * @dataProvider activeStates
	 *
	 * @return void
	 */
	public function testARestrictedDecisionReachesNoPublicSurface(string $state): void {
		$service = $this->catalogue([self::restriction($state)]);

		try {
			$service->publish('decision', 'decision-1', 'griffier');
			self::fail('A decision under a ' . $state . ' restriction was published.');
		} catch (AccessDeniedException $e) {
			self::assertStringContainsString('confidential', $e->getMessage());
		}

		self::assertSame([], $this->written, 'Something was written for a restricted decision.');
		self::assertSame([], $this->catalogued, 'A restricted decision reached the catalogue.');
	}//end testARestrictedDecisionReachesNoPublicSurface()

	/**
	 * A dissolved restriction, or one on another decision, no longer keeps
	 * this decision out.
	 *
	 * @return void
	 */
	public function testADissolvedRestrictionNoLongerKeepsTheDecisionOut(): void {
		$other   = ['scope' => 'decision', 'targetDecision' => 'decision-2', 'lifecycle' => 'imposed'];
		$service = $this->catalogue([self::restriction('dissolved'), $other]);

		$result = $service->publish('decision', 'decision-1', 'griffier');

		self::assertSame('published', $result['record']['status']);
		self::assertCount(1, $this->catalogued);
	}//end testADissolvedRestrictionNoLongerKeepsTheDecisionOut()

	/**
	 * Restrictions that cannot be read stop the publication: the controller
	 * answers the exception with a 503, and nothing is written.
	 *
	 * @return void
	 */
	public function testRestrictionsThatCannotBeReadPublishNothing(): void {
		$service = $this->catalogue(null);

		try {
			$service->publish('decision', 'decision-1', 'griffier');
			self::fail('A decision whose restrictions could not be read was published.');
		} catch (ConfidentialityUnreadableException $e) {
			self::assertStringContainsString('confidential', $e->getMessage());
		}

		self::assertSame([], $this->written);
		self::assertSame([], $this->catalogued);
	}//end testRestrictionsThatCannotBeReadPublishNothing()

	/**
	 * The decision's own publish action refuses a restricted decision and
	 * leaves it unpublished.
	 *
	 * @param string $state The restriction lifecycle
	 *
	 * @dataProvider activeStates
	 *
	 * @return void
	 */
	public function testTheDecisionsOwnPublishActionRefusesARestrictedDecision(string $state): void {
		[$service, $register] = $this->direct([self::restriction($state)]);

		$result = $service->publish(decisionId: 'decision-1', actorUid: 'admin');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result['status']);
		self::assertStringContainsString('confidential', (string)$result['data']['message']);
		self::assertSame([], $register->writes());
	}//end testTheDecisionsOwnPublishActionRefusesARestrictedDecision()

	/**
	 * The decision's own publish action fails closed with a 503 when the
	 * restrictions cannot be read.
	 *
	 * @return void
	 */
	public function testTheDecisionsOwnPublishActionFailsClosed(): void {
		[$service, $register] = $this->direct(null);

		$result = $service->publish(decisionId: 'decision-1', actorUid: 'admin');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result['status']);
		self::assertSame([], $register->writes());
	}//end testTheDecisionsOwnPublishActionFailsClosed()

	/**
	 * With no active restriction the decision's own publish action still
	 * publishes.
	 *
	 * @return void
	 */
	public function testTheDecisionsOwnPublishActionPublishesAnUnrestrictedDecision(): void {
		[$service, $register] = $this->direct([self::restriction('dissolved')]);

		$result = $service->publish(decisionId: 'decision-1', actorUid: 'admin');

		self::assertSame(Http::STATUS_OK, $result['status']);
		self::assertSame('public', $register->lastWriteTo('decision')['isPublished'] ?? null);
	}//end testTheDecisionsOwnPublishActionPublishesAnUnrestrictedDecision()
}//end class
