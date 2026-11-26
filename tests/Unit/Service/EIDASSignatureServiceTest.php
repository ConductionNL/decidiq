<?php

/**
 * Unit tests for EIDASSignatureService (signs through filinq's rail).
 *
 * filinq's request event is the stub in tests/Stubs/Filinq/Event; the event
 * dispatcher double plays filinq's listener, which writes the request id (or
 * a refusal) back onto the same event instance.
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
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\FilinqSigningRequest;
use OCA\Filinq\Event\DocumentSigningRequestedEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for EIDASSignatureService.
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
 */
class EIDASSignatureServiceTest extends TestCase {

	/**
	 * The events dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * The stage saves, as [uuid, object, rbac].
	 *
	 * @var array<int, array{0: ?string, 1: array<string, mixed>, 2: bool}>
	 */
	private array $saves = [];

	/**
	 * The filters of each findAll() call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * Build the service.
	 *
	 * @param callable|null           $filinq  Plays filinq's listener; null = nobody handles the event
	 * @param array<int, ObjectEntity> $stages  The signature stages findAll() answers
	 * @param bool                    $present Whether filinq's classes load
	 * @param \Throwable|null         $payload Thrown by the payload builder
	 *
	 * @return EIDASSignatureService
	 */
	private function makeService(?callable $filinq = null, array $stages = [], bool $present = true, ?\Throwable $payload = null): EIDASSignatureService {
		$request = $this->createMock(originalClassName: FilinqSigningRequest::class);
		if ($payload !== null) {
			$request->method('payload')->willThrowException($payload);
		} else {
			$request->method('payload')->willReturn(
				[
					'documentFileId' => 812,
					'documentName' => 'Notulen.pdf',
					'signers' => [
						['userId' => 'chair', 'displayName' => 'Chair', 'email' => 'c@example.org', 'order' => 1],
						['userId' => 'clerk', 'displayName' => 'Clerk', 'email' => '', 'order' => 2],
					],
					'signatureLevel' => 'QES',
					'signingMode' => 'sequential',
				]
			);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				FilinqSigningRequest::class => $request,
				default => throw new \RuntimeException('Service ' . $id . ' is not registered'),
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($filinq): void {
				$this->dispatched[] = $event;
				if ($filinq !== null) {
					$filinq($event);
				}
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($stages): array {
				$this->finds[] = $config['filters'];
				return $stages;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, $options, bool $_rbac = true) {
				$this->saves[] = [$options->uuid, $object, $_rbac];
				return new ObjectEntity();
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

		$args = [
			'container' => $container,
			'logger' => $this->createMock(LoggerInterface::class),
			'auditLogService' => $audit,
			'objectService' => $objectService,
			'dispatcher' => $dispatcher,
		];

		if ($present === true) {
			return new EIDASSignatureService(...$args);
		}

		return new class(...$args) extends EIDASSignatureService {
			/**
			 * filinq is not installed.
			 *
			 * @param string $relative The class name
			 *
			 * @return string|null
			 */
			protected function filinqClass(string $relative): ?string {
				return null;
			}
		};
	}//end makeService()

	/**
	 * A stage as OpenRegister returns it.
	 *
	 * @param string               $uuid The stage UUID
	 * @param array<string, mixed> $data The stage data
	 *
	 * @return ObjectEntity
	 */
	private function stage(string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(array_merge(['id' => $uuid, 'method' => 'signature', 'signedDocument' => 'min-1'], $data));
		return $entity;
	}//end stage()

	/**
	 * A signature stage asks filinq to sign: the event names the subject, the
	 * PDF and the signers in order, and the request id is stored on the stage.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-a-signature-stage-asks-filinq-to-sign
	 *
	 * @return void
	 */
	public function testSignatureStageAsksFilinqToSign(): void {
		$service = $this->makeService(
			filinq: static function (DocumentSigningRequestedEvent $event): void {
				$event->setSigningRequestId('sr-9');
				$event->setHandled(true);
			},
			stages: [$this->stage(uuid: 'stage-1')]
		);

		$result = $service->initializeSigningRequest('min-1', ['p-1', 'p-2']);

		$this->assertTrue($result['success']);
		$this->assertSame('sr-9', $result['requestId']);
		$this->assertNull($result['signingUrl']);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(DocumentSigningRequestedEvent::class, $event);
		$this->assertSame('decidiq', $event->getProvenance()->getSourceApp());
		$this->assertSame('decidiq', $event->getProvenance()->getSubjectRegister());
		$this->assertSame('minutes', $event->getProvenance()->getSubjectSchema());
		$this->assertSame('min-1', $event->getProvenance()->getSubjectId());
		$this->assertSame('812', $event->getDocumentReference());
		$this->assertSame('Notulen.pdf', $event->getSubjectLabel());
		$this->assertSame('QES', $event->getSignatureLevel());
		$this->assertSame('sequential', $event->getSigningMode());
		$this->assertSame(['chair', 'clerk'], array_column($event->getSigners(), 'userId'));

		$this->assertSame(['register' => 'decidiq', 'schema' => 'decision-stage', 'method' => 'signature', 'signedDocument' => 'min-1'], $this->finds[0]);
		$this->assertCount(1, $this->saves);
		$this->assertSame('stage-1', $this->saves[0][0]);
		$this->assertSame('sr-9', $this->saves[0][1]['signingReference']);
		$this->assertFalse($this->saves[0][2], 'the stage bookkeeping is written in system context');
	}//end testSignatureStageAsksFilinqToSign()

	/**
	 * A decision list is signed from its meeting: the subject schema is meeting.
	 *
	 * @return void
	 */
	public function testDecisionListIsSignedFromItsMeeting(): void {
		$service = $this->makeService(
			filinq: static function (DocumentSigningRequestedEvent $event): void {
				$event->setSigningRequestId('sr-2');
				$event->setHandled(true);
			}
		);

		$service->initializeSigningRequest('meeting-1', ['p-1'], 'decision-list');

		$this->assertSame('meeting', $this->dispatched[0]->getProvenance()->getSubjectSchema());
		$this->assertSame('decision-list/meeting-1', $this->dispatched[0]->getProvenance()->getExternalReference());
	}//end testDecisionListIsSignedFromItsMeeting()

	/**
	 * Without filinq the stage stays unresolved: nothing is dispatched or saved.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-no-signing-provider-fails-closed
	 *
	 * @return void
	 */
	public function testNoFilinqFailsClosed(): void {
		$result = $this->makeService(present: false)->initializeSigningRequest('min-1', ['p-1']);

		$this->assertFalse($result['success']);
		$this->assertNull($result['requestId']);
		$this->assertStringContainsString('filinq is not installed', $result['message']);
		$this->assertSame([], $this->dispatched);
		$this->assertSame([], $this->saves);
	}//end testNoFilinqFailsClosed()

	/**
	 * An event nobody handles fails closed.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-no-signing-provider-fails-closed
	 *
	 * @return void
	 */
	public function testUnhandledRequestFailsClosed(): void {
		$result = $this->makeService(stages: [$this->stage(uuid: 'stage-1')])->initializeSigningRequest('min-1', ['p-1']);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('filinq did not take the signing request', $result['message']);
		$this->assertSame([], $this->saves);
	}//end testUnhandledRequestFailsClosed()

	/**
	 * filinq's refusal is passed on as the answer.
	 *
	 * @return void
	 */
	public function testRefusalIsPassedOn(): void {
		$result = $this->makeService(
			filinq: static function (DocumentSigningRequestedEvent $event): void {
				$event->setRefusal('Filinq refused the signing request from decidiq.');
			}
		)->initializeSigningRequest('min-1', ['p-1']);

		$this->assertFalse($result['success']);
		$this->assertSame('Filinq refused the signing request from decidiq.', $result['message']);
	}//end testRefusalIsPassedOn()

	/**
	 * A record without a PDF fails closed with the builder's reason.
	 *
	 * @return void
	 */
	public function testMissingPdfFailsClosed(): void {
		$result = $this->makeService(payload: new \RuntimeException('No PDF of this minutes to sign was found.'))->initializeSigningRequest('min-1', ['p-1']);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('No PDF of this minutes', $result['message']);
		$this->assertSame([], $this->dispatched);
	}//end testMissingPdfFailsClosed()

	/**
	 * Empty input is refused before filinq is asked.
	 *
	 * @return void
	 */
	public function testInitializeRejectsEmptySignatories(): void {
		$result = $this->makeService()->initializeSigningRequest('min-1', []);

		$this->assertFalse($result['success']);
		$this->assertSame([], $this->dispatched);
	}//end testInitializeRejectsEmptySignatories()

	/**
	 * A signed request resolves the stages that wait on it, matched on the
	 * request id.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-completed-filinq-signing-resolves-the-stage
	 *
	 * @return void
	 */
	public function testSignedRequestResolvesItsStages(): void {
		$service = $this->makeService(stages: [$this->stage(uuid: 'stage-1', data: ['signingReference' => 'sr-9'])]);

		$service->resolveSignatureStage(minutesId: 'min-1', signingReference: 'sr-9');

		$this->assertSame('sr-9', $this->finds[0]['signingReference']);
		$this->assertArrayNotHasKey('signedDocument', $this->finds[0]);
		$saved = $this->saves[0][1];
		$this->assertSame('adopted', $saved['outcome']);
		$this->assertSame('decided', $saved['status']);
		$this->assertNotEmpty($saved['decidedAt']);
		$this->assertSame('signed', $saved['signingOutcome']);
		$this->assertSame('min-1', $saved['signedDocument']);
	}//end testSignedRequestResolvesItsStages()

	/**
	 * A declined request leaves the stage unresolved and records why.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-a-declined-signing-leaves-the-stage-open
	 *
	 * @return void
	 */
	public function testDeclinedRequestLeavesTheStageOpen(): void {
		$service = $this->makeService(stages: [$this->stage(uuid: 'stage-1', data: ['signingReference' => 'sr-9', 'status' => 'active'])]);

		$this->assertSame(1, $service->recordUnsignedOutcome(signingReference: 'sr-9', outcome: 'declined'));

		$saved = $this->saves[0][1];
		$this->assertSame('declined', $saved['signingOutcome']);
		$this->assertSame('active', $saved['status']);
		$this->assertArrayNotHasKey('outcome', $saved);
		$this->assertArrayNotHasKey('decidedAt', $saved);
	}//end testDeclinedRequestLeavesTheStageOpen()

	/**
	 * The checks filinq performs never answer valid in decidiq.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
	 *
	 * @return void
	 */
	public function testChecksFilinqPerformsFailClosed(): void {
		$service = $this->makeService();

		$this->assertFalse($service->verifySignature('req-1', 'sig')['valid']);
		$this->assertFalse($service->validateCertificateChain('thumb')['valid']);
		$this->assertFalse($service->finalizeMinutes('min-1', [['signer' => 'a']])['success']);
		$this->assertSame('pending', $service->fetchSigningResult('req-1')['status']);
		$this->assertSame('certificateThumbprint is required.', $service->validateCertificateChain('')['message']);
		$this->assertSame([], $this->saves, 'a client-sent signature list never marks minutes signed');
	}//end testChecksFilinqPerformsFailClosed()
}//end class
