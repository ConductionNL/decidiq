<?php

/**
 * Unit tests for DecisionIntegrationService.
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
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the contract-decision hub integration service:
 * outcome status derivation, idempotent create-decision, and SSRF guard.
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
 */
class DecisionIntegrationServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var DecisionIntegrationService
	 */
	private DecisionIntegrationService $service;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock audit log service.
	 *
	 * @var AuditLogService&MockObject
	 */
	private AuditLogService&MockObject $auditLog;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->auditLog = $this->createMock(AuditLogService::class);

		$this->container->method('get')
			->willReturnCallback(function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException("Service not found: {$id}");
			});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				$replacements = [];
				foreach ((array)$parameters as $index => $value) {
					$replacements['%' . ((int)$index + 1) . '$s'] = (string)$value;
				}

				return strtr($text, $replacements);
			}
		);

		$this->service = new DecisionIntegrationService(
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
			auditLog: $this->auditLog,
			l10n: $l10n,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity double that serializes to the given array.
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * and ::saveObject() are typed `?ObjectEntity` / `ObjectEntity` in
	 * production, so a stdClass mock is a value the service can never hand the
	 * code under test (#399).
	 *
	 * @param array<string, mixed> $data Object payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	// ─── getOutcomeEnvelope status derivation ────────────────────────────────

	/**
	 * lifecycle=decided + outcome=adopted → status=approved.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomeApprovedWhenDecidedAndAdopted(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'lifecycle' => 'decided',
				'outcome' => 'adopted',
				'decisionType' => 'contract',
				'decisionDate' => '2026-06-14T10:00:00Z',
			])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-1');

		self::assertNotNull($result);
		self::assertSame('approved', $result['status']);
		self::assertSame('dec-1', $result['decisionId']);
		self::assertFalse($result['signed']);

	}//end testGetOutcomeApprovedWhenDecidedAndAdopted()

	/**
	 * lifecycle=enacted + outcome=adopted → status=approved.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomeApprovedWhenEnactedAndAdopted(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-2',
				'lifecycle' => 'enacted',
				'outcome' => 'adopted',
				'decisionType' => 'contract-renewal',
				'decisionDate' => '2026-06-14T11:00:00Z',
			])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-2');

		self::assertNotNull($result);
		self::assertSame('approved', $result['status']);

	}//end testGetOutcomeApprovedWhenEnactedAndAdopted()

	/**
	 * lifecycle=decided + outcome=rejected → status=rejected.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomeRejectedWhenDecidedAndRejected(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-3',
				'lifecycle' => 'decided',
				'outcome' => 'rejected',
				'decisionType' => 'report-adoption',
				'decisionDate' => '2026-06-14T12:00:00Z',
			])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-3');

		self::assertNotNull($result);
		self::assertSame('rejected', $result['status']);

	}//end testGetOutcomeRejectedWhenDecidedAndRejected()

	/**
	 * lifecycle=withdrawn → status=withdrawn regardless of outcome.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomeWithdrawnWhenWithdrawn(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-4',
				'lifecycle' => 'withdrawn',
				'outcome' => 'adopted',
				'decisionType' => 'contract',
			])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-4');

		self::assertNotNull($result);
		self::assertSame('withdrawn', $result['status']);

	}//end testGetOutcomeWithdrawnWhenWithdrawn()

	/**
	 * lifecycle=draft → status=pending.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomePendingWhenDraft(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-5',
				'lifecycle' => 'draft',
				'outcome' => '',
				'decisionType' => 'contract',
			])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-5');

		self::assertNotNull($result);
		self::assertSame('pending', $result['status']);
		self::assertNull($result['decidedAt']);

	}//end testGetOutcomePendingWhenDraft()

	/**
	 * Decision not found → getOutcomeEnvelope returns null.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testGetOutcomeReturnsNullWhenNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$result = $this->service->getOutcomeEnvelope(decisionId: 'dec-404');

		self::assertNull($result);

	}//end testGetOutcomeReturnsNullWhenNotFound()

	// NOTE: the isAuthorizedToReadOutcome (REQ-DCDH-101) and
	// isAuthorizedToSubscribe (REQ-DCDH-102) cases that used to live here moved
	// with their implementation into DecisionIntegrationAuthorizationGuard,
	// extracted to bring this class back under the PHPMD complexity budget.
	// They are covered 1:1 in tests/Unit/Service/DecisionIntegrationAuthorizationGuardTest.php
	// — the same twelve cases, exercising the real guard.

	// ─── createDecision idempotency ──────────────────────────────────────────

	/**
	 * When a Decision with the same (sourceApp, subjectId) tuple already exists,
	 * createDecision returns the existing id with created=false (REQ-DCDH-002).
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testCreateDecisionIdempotentHitReturnsExistingId(): void {
		$existingEntity = $this->entity(['id' => 'dec-existing', 'uuid' => 'dec-existing']);
		$this->objectService->method('findAll')->willReturn([$existingEntity]);

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'contract',
				'title' => 'Test contract',
				'text' => 'Body',
				'decisionDate' => '2026-06-14T10:00:00Z',
				'sourceApp' => 'openregister',
				'subjectId' => 'obj-123',
			],
			actorId: 'alice'
		);

		self::assertTrue($result['success']);
		self::assertSame('dec-existing', $result['decisionId']);
		self::assertFalse($result['created']);

	}//end testCreateDecisionIdempotentHitReturnsExistingId()

	/**
	 * When no matching Decision exists, createDecision saves and returns created=true.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testCreateDecisionCreatesNewWhenNoTupleMatch(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$savedEntity = $this->entity(['id' => 'dec-new', 'uuid' => 'dec-new']);
		$this->objectService->method('saveObject')->willReturn($savedEntity);
		$this->auditLog->expects(self::once())->method('append');

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'contract-renewal',
				'title' => 'Renewal 2027',
				'text' => 'Renewed.',
				'decisionDate' => '2026-06-14T10:00:00Z',
				'sourceApp' => 'openregister',
				'subjectId' => 'obj-456',
			],
			actorId: 'bob'
		);

		self::assertTrue($result['success']);
		self::assertSame('dec-new', $result['decisionId']);
		self::assertTrue($result['created']);

	}//end testCreateDecisionCreatesNewWhenNoTupleMatch()

	/**
	 * A flow-raised decision without a `text` derives it from the delegation
	 * context (the ask's question), so the created object carries the full
	 * schema-required triple (title, text, decisionType) from birth. An empty
	 * `decisionDate` is omitted entirely — an empty string is not a valid
	 * `date-time` value.
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	public function testCreateDecisionDerivesTextFromDelegationContext(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$captured = null;
		$savedEntity = $this->entity(['id' => 'dec-ctx', 'uuid' => 'dec-ctx']);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $savedEntity) {
				$captured = $object;
				return $savedEntity;
			}
		);

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'advice',
				'sourceApp' => 'dossiq',
				'subjectRegister' => 'dossiq',
				'subjectSchema' => 'case',
				'subjectId' => 'case-9',
				'subjectLabel' => 'Vergunning Hoofdstraat 12',
				'externalReference' => 'case-9',
				'context' => [
					'question' => 'Kan de vergunning worden verleend?',
					'advisor' => 'jan',
				],
			],
			actorId: 'alice'
		);

		self::assertTrue($result['success']);
		self::assertIsArray($captured);
		self::assertSame('Kan de vergunning worden verleend?', $captured['text']);
		self::assertSame('Vergunning Hoofdstraat 12', $captured['title']);
		self::assertSame('advice', $captured['decisionType']);
		self::assertArrayNotHasKey('decisionDate', $captured);

	}//end testCreateDecisionDerivesTextFromDelegationContext()

	/**
	 * With no text and no usable delegation context, the fallback text and
	 * title name the source app and the subject, so the decision is still
	 * schema-valid from birth and traceable to the case it was raised for.
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	public function testCreateDecisionFallbackTextNamesTheSource(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$captured = null;
		$savedEntity = $this->entity(['id' => 'dec-fb', 'uuid' => 'dec-fb']);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $savedEntity) {
				$captured = $object;
				return $savedEntity;
			}
		);

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'bezwaar-decision',
				'sourceApp' => 'dossiq',
				'subjectRegister' => 'dossiq',
				'subjectSchema' => 'case',
				'subjectId' => 'case-42',
				'externalReference' => 'case-42',
			],
			actorId: 'alice'
		);

		self::assertTrue($result['success']);
		self::assertIsArray($captured);
		self::assertSame('Decision requested by dossiq for case case-42.', $captured['text']);
		self::assertSame('Decision requested by dossiq', $captured['title']);
		self::assertNotSame('', trim((string)$captured['title']));
		self::assertNotSame('', trim((string)$captured['text']));

	}//end testCreateDecisionFallbackTextNamesTheSource()

	/**
	 * A caller-supplied text always wins over the delegation context.
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testCreateDecisionKeepsSuppliedText(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$captured = null;
		$savedEntity = $this->entity(['id' => 'dec-sup', 'uuid' => 'dec-sup']);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $savedEntity) {
				$captured = $object;
				return $savedEntity;
			}
		);

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'contract',
				'title' => 'Contract 2027',
				'text' => 'The supplied body.',
				'decisionDate' => '2026-09-01T10:00:00Z',
				'sourceApp' => 'openregister',
				'subjectId' => 'obj-1',
				'context' => ['question' => 'Ignored.'],
			],
			actorId: 'bob'
		);

		self::assertTrue($result['success']);
		self::assertIsArray($captured);
		self::assertSame('The supplied body.', $captured['text']);
		self::assertSame('Contract 2027', $captured['title']);
		self::assertSame('2026-09-01T10:00:00Z', $captured['decisionDate']);

	}//end testCreateDecisionKeepsSuppliedText()

	/**
	 * An unrecognised decisionType is rejected with success=false.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testCreateDecisionRejectsUnknownDecisionType(): void {
		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => 'totally-made-up',
				'title' => 'Test',
				'text' => 'Body',
				'decisionDate' => '2026-06-14T10:00:00Z',
			],
			actorId: 'alice'
		);

		self::assertFalse($result['success']);
		self::assertStringContainsString('totally-made-up', $result['message']);

	}//end testCreateDecisionRejectsUnknownDecisionType()

	/**
	 * Fleet decision types raised by dossiq's delegation services are accepted.
	 *
	 * dossiq raises `advice` (AdviceDelegationService) and `bezwaar-decision`
	 * (BezwaarDecisionDelegationService); before these joined ALLOWED_TYPES the
	 * hub refused them and the whole decidiq leg of the case flow failed closed.
	 *
	 * @param string $decisionType The fleet-raised decision type slug.
	 *
	 * @dataProvider provideDelegatedDecisionTypes
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 any fleet app can raise a Decision of any decisionType.
	 *
	 * @return void
	 */
	public function testCreateDecisionAcceptsDelegatedDecisionType(string $decisionType): void {
		$this->objectService->method('findAll')->willReturn([]);
		$savedEntity = $this->entity(['id' => 'dec-'.$decisionType, 'uuid' => 'dec-'.$decisionType]);
		$this->objectService->method('saveObject')->willReturn($savedEntity);
		$this->auditLog->expects(self::once())->method('append');

		$result = $this->service->createDecision(
			decisionData: [
				'decisionType' => $decisionType,
				'title' => 'Delegated decision',
				'text' => 'Raised by dossiq.',
				'decisionDate' => '2026-09-01T10:00:00Z',
				'sourceApp' => 'dossiq',
				'subjectId' => 'case-789',
			],
			actorId: 'carol'
		);

		self::assertTrue($result['success']);
		self::assertSame('dec-'.$decisionType, $result['decisionId']);
		self::assertTrue($result['created']);

	}//end testCreateDecisionAcceptsDelegatedDecisionType()

	/**
	 * Decision types dossiq's delegation services send to the hub.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideDelegatedDecisionTypes(): array {
		return [
			'advice' => ['advice'],
			'bezwaar-decision' => ['bezwaar-decision'],
		];
	}//end provideDelegatedDecisionTypes()

	/**
	 * ALLOWED_TYPES mirrors every declared home of the decisionType vocabulary.
	 *
	 * The vocabulary lives in four places: the PHP guard, the Decision enum in
	 * the monolithic register, its copy in the mock register, and the
	 * DecisionTemplate narrowing fragment. A value added to one home but not
	 * the others either refuses valid fleet decisions (guard behind schema) or
	 * persists objects the schema rejects (guard ahead of schema). This pins
	 * all four together (closed-vocabulary rule: extend the vocabulary
	 * everywhere, never bypass the check).
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 decisionType enum gains the fleet-raised types additively.
	 *
	 * @return void
	 */
	public function testAllowedTypesMatchSchemaEnum(): void {
		$reflection = new \ReflectionClassConstant(DecisionIntegrationService::class, 'ALLOWED_TYPES');
		$allowedTypes = $reflection->getValue();

		$settingsDir = __DIR__.'/../../../lib/Settings';
		$homes = [
			'decidesk_register.json Decision' => [
				'file' => $settingsDir.'/decidesk_register.json',
				'path' => ['components', 'schemas', 'Decision', 'properties', 'decisionType', 'enum'],
			],
			'decidiq_mock_register.json Decision' => [
				'file' => $settingsDir.'/decidiq_mock_register.json',
				'path' => ['components', 'schemas', 'Decision', 'properties', 'decisionType', 'enum'],
			],
			'register.d/68 DecisionTemplate' => [
				'file' => $settingsDir.'/register.d/68-unified-decision-templates.json',
				'path' => ['components', 'schemas', 'DecisionTemplate', 'properties', 'decisionType', 'enum'],
			],
		];

		foreach ($homes as $label => $home) {
			$data = json_decode(json: (string) file_get_contents($home['file']), associative: true);
			self::assertIsArray($data, "{$label}: register JSON must parse");
			foreach ($home['path'] as $key) {
				self::assertArrayHasKey($key, $data, "{$label}: missing key '{$key}'");
				$data = $data[$key];
			}

			self::assertSame($allowedTypes, $data, "{$label}: decisionType enum diverged from ALLOWED_TYPES");
		}

	}//end testAllowedTypesMatchSchemaEnum()

	// ─── registerOutcomeCallback SSRF guard ──────────────────────────────────

	/**
	 * A plain HTTP callback URL is rejected (SSRF guard — scheme must be https).
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testSubscribeRejectsHttpCallbackUrl(): void {
		$result = $this->service->registerOutcomeCallback(
			decisionId: 'dec-1',
			callbackUrl: 'http://external.example.com/hook',
			actorId: 'alice'
		);

		self::assertFalse($result['success']);
		self::assertSame('ssrf_rejected', $result['code']);

	}//end testSubscribeRejectsHttpCallbackUrl()

	/**
	 * A loopback callback URL is rejected (SSRF guard — private host).
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testSubscribeRejectsLoopbackCallbackUrl(): void {
		$result = $this->service->registerOutcomeCallback(
			decisionId: 'dec-1',
			callbackUrl: 'https://localhost/hook',
			actorId: 'alice'
		);

		self::assertFalse($result['success']);
		self::assertSame('ssrf_rejected', $result['code']);

	}//end testSubscribeRejectsLoopbackCallbackUrl()

	/**
	 * A private-IP callback URL is rejected (SSRF guard — RFC-1918 range).
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testSubscribeRejectsPrivateIpCallbackUrl(): void {
		$result = $this->service->registerOutcomeCallback(
			decisionId: 'dec-1',
			callbackUrl: 'https://192.168.1.1/hook',
			actorId: 'alice'
		);

		self::assertFalse($result['success']);
		self::assertSame('ssrf_rejected', $result['code']);

	}//end testSubscribeRejectsPrivateIpCallbackUrl()

	/**
	 * A valid HTTPS public callback URL is accepted when the Decision exists.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testSubscribeAcceptsValidHttpsCallbackUrl(): void {
		$decisionEntity = $this->entity(['id' => 'dec-1', 'decisionType' => 'contract']);
		$this->objectService->method('find')->willReturn($decisionEntity);
		$savedEntity = $this->entity(['id' => 'dec-1']);
		$this->objectService->method('saveObject')->willReturn($savedEntity);
		$this->auditLog->expects(self::once())->method('append');

		$result = $this->service->registerOutcomeCallback(
			decisionId: 'dec-1',
			callbackUrl: 'https://openregister.example.com/callback/hook',
			actorId: 'alice'
		);

		self::assertTrue($result['success']);
		self::assertArrayHasKey('subscriptionId', $result);

	}//end testSubscribeAcceptsValidHttpsCallbackUrl()

	/**
	 * Subscribe returns not_found when the Decision does not exist.
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-5
	 *
	 * @return void
	 */
	public function testSubscribeReturnsNotFoundWhenDecisionMissing(): void {
		$this->objectService->method('find')->willReturn(null);

		$result = $this->service->registerOutcomeCallback(
			decisionId: 'dec-404',
			callbackUrl: 'https://openregister.example.com/callback/hook',
			actorId: 'alice'
		);

		self::assertFalse($result['success']);
		self::assertSame('not_found', $result['code']);

	}//end testSubscribeReturnsNotFoundWhenDecisionMissing()
}//end class
