<?php

/**
 * Unit tests for ConsultationResponseDocumentService and WorksCouncilConsultationController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\WorksCouncilConsultationController;
use OCA\Decidiq\Exception\ConsultationResponseRefusedException;
use OCA\Decidiq\Service\ConsultationResponseDocumentService;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The document is written only for an adopted response, stored on the traject,
 * recorded and linked with every field kept, and the PDF falls back honestly.
 *
 * @covers \OCA\Decidiq\Service\ConsultationResponseDocumentService
 * @covers \OCA\Decidiq\Controller\WorksCouncilConsultationController
 * @covers \OCA\Decidiq\Exception\ConsultationResponseRefusedException
 */
final class ConsultationResponseDocumentServiceTest extends TestCase {

	private const ID = '11111111-1111-4111-8111-111111111111';

	private const BODY = '22222222-2222-4222-8222-222222222222';

	/**
	 * The stored traject.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $traject = null;

	/**
	 * Every saveObject call: schema, uuid and object.
	 *
	 * @var list<array{schema: string, uuid: string|null, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Every file stored: object, name and content.
	 *
	 * @var list<array{object: mixed, name: string, content: mixed}>
	 */
	private array $files = [];

	private ?string $pdf = null;

	private bool $filesDown = false;

	protected function setUp(): void {
		$this->traject = [
			'type' => 'adviceRequest',
			'worArticle' => 'art. 25 lid 1 sub n WOR',
			'subject' => 'Uitbesteding ICT-beheer: "fase 1/2"',
			'director' => '33333333-3333-4333-8333-333333333333',
			'governanceBody' => self::BODY,
			'receivedDate' => '2026-01-06',
			'requestedResponseDate' => '2026-03-01',
			'lifecycle' => 'adopted',
			'responseOutcome' => 'advice-with-conditions',
			'responseText' => 'De OR adviseert positief onder voorwaarde van een sociaal plan.',
			'responseDate' => '2026-02-24',
		];
	}

	public function testAMarkdownDocumentIsStoredRecordedAndLinkedWithEveryFieldKept(): void {
		$result = $this->service()->generate(id: self::ID, format: 'markdown');

		self::assertSame(['path' => '/decidiq/traject/Advice Uitbesteding ICT-beheer fase 1 2 2026-02-24.md', 'format' => 'markdown', 'docudesk' => false, 'responseDocument' => 'doc-1'], $result);
		self::assertCount(1, $this->files);
		self::assertSame(self::ID, $this->files[0]['object']);
		self::assertStringStartsWith("# Advice of the works council\n", $this->files[0]['content']);
		self::assertStringContainsString("## Advice with conditions\n\nDe OR adviseert positief", $this->files[0]['content']);

		[$document, $link] = $this->saved;
		self::assertSame('digital-document', $document['schema']);
		self::assertSame(['name' => 'Advice Uitbesteding ICT-beheer fase 1 2 2026-02-24.md', 'documentType' => 'works-council-response', 'description' => 'Uitbesteding ICT-beheer: "fase 1/2"', 'encodingFormat' => 'text/markdown', 'fileId' => 42], $document['object']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'digital-document', payload: $document['object']));

		self::assertSame(['consultation-request', self::ID], [$link['schema'], $link['uuid']]);
		self::assertSame($this->traject + ['responseDocument' => 'doc-1'], $link['object']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'consultation-request', payload: $link['object']));
	}

	public function testAPdfIsStoredWhenFilinqRendersOne(): void {
		$this->pdf = '%PDF-1.7 bytes';
		$this->traject['type'] = 'consent-request';
		$this->traject['responseOutcome'] = 'consent-granted';

		$result = $this->service()->generate(id: self::ID, format: 'pdf');

		self::assertSame('pdf', $result['format']);
		self::assertTrue($result['docudesk']);
		self::assertArrayNotHasKey('note', $result);
		self::assertSame('%PDF-1.7 bytes', $this->files[0]['content']);
		self::assertStringEndsWith('.pdf', $this->files[0]['name']);
		self::assertStringStartsWith('Consent ', $this->files[0]['name']);
		self::assertSame('application/pdf', $this->saved[0]['object']['encodingFormat']);
	}

	public function testWithoutFilinqThePdfFallsBackToMarkdownAndSaysSo(): void {
		$result = $this->service()->generate(id: self::ID, format: 'pdf');

		self::assertSame('markdown', $result['format']);
		self::assertFalse($result['docudesk']);
		self::assertSame('filinq is not available on this instance: a markdown document was stored instead of a PDF.', $result['note']);
		self::assertStringEndsWith('.md', $this->files[0]['name']);
	}

	public function testAResponseThatIsNotAdoptedIsRefusedAndNothingIsWritten(): void {
		foreach (['consultationMeeting' => 'The response is written once the works council has adopted it.', 'sent' => 'Record the response outcome before writing the document.'] as $state => $message) {
			$this->traject['lifecycle'] = $state;
			if ($state === 'sent') {
				unset($this->traject['responseOutcome']);
			}

			try {
				$this->service()->generate(id: self::ID, format: 'markdown');
				self::fail('A refusal was expected for ' . $state);
			} catch (ConsultationResponseRefusedException $e) {
				self::assertSame([$message, 409], [$e->getMessage(), $e->getStatus()]);
			}
		}

		self::assertSame([], $this->files);
		self::assertSame([], $this->saved);
	}

	public function testAnUnknownFormatAMissingTrajectAndAFilesFailureAreRefused(): void {
		$cases = [
			[fn () => $this->service()->generate(id: self::ID, format: 'docx'), 422],
			[function () {
				$this->traject = null;
				return $this->service()->generate(id: self::ID, format: 'markdown');
			}, 404],
		];
		foreach ($cases as [$call, $status]) {
			try {
				$call();
				self::fail('A refusal was expected');
			} catch (ConsultationResponseRefusedException $e) {
				self::assertSame($status, $e->getStatus());
			}
		}

		$this->setUp();
		$this->filesDown = true;
		try {
			$this->service()->generate(id: self::ID, format: 'markdown');
			self::fail('A refusal was expected');
		} catch (ConsultationResponseRefusedException $e) {
			self::assertSame(503, $e->getStatus());
		}

		self::assertSame([], $this->saved);
	}

	public function testTheControllerRefusesACallerOutsideTheWorksCouncilsScope(): void {
		$response = $this->controller(admin: false, inScope: false)->generateResponseDocument(id: self::ID);

		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->files);
		self::assertSame([], $this->saved);
	}

	public function testTheControllerLetsTheSecretaryOrAnAdministratorWrite(): void {
		$response = $this->controller(admin: false, inScope: true)->generateResponseDocument(id: self::ID);
		self::assertSame(201, $response->getStatus());
		self::assertSame('doc-1', $response->getData()['responseDocument']);

		$response = $this->controller(admin: true, inScope: false, format: 'docx')->generateResponseDocument(id: self::ID);
		self::assertSame(422, $response->getStatus());
	}

	public function testTheControllerAnswers401WithoutAUserAnd404ForAnUnreadableTraject(): void {
		self::assertSame(401, $this->controller(admin: true, inScope: true, signedIn: false)->generateResponseDocument(id: self::ID)->getStatus());

		$this->traject = null;
		self::assertSame(404, $this->controller(admin: true, inScope: true)->generateResponseDocument(id: self::ID)->getStatus());
	}

	/**
	 * The service over an in-memory store.
	 *
	 * @return ConsultationResponseDocumentService
	 */
	private function service(): ConsultationResponseDocumentService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntityInterface {
				if ($this->traject === null || $id !== self::ID || $schema !== 'consultation-request') {
					return null;
				}

				return $this->entity(object: $this->traject, uuid: self::ID);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->assertSame('decidiq', $register);
				$this->saved[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $object];
				return $this->entity(object: $object, uuid: $uuid ?? ('doc-' . count($this->saved)));
			}
		);

		$files = $this->createMock(FileService::class);
		$files->method('addFile')->willReturnCallback(
			function ($objectEntity, string $fileName, mixed $content): File {
				if ($this->filesDown === true) {
					throw new RuntimeException('no storage');
				}

				$this->files[] = ['object' => $objectEntity, 'name' => $fileName, 'content' => $content];
				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn('/decidiq/traject/' . $fileName);
				$file->method('getId')->willReturn(42);
				return $file;
			}
		);

		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturnCallback(fn (string $html): ?string => ($this->pdf !== null && str_contains($html, '&quot;fase 1/2&quot;') === true) ? $this->pdf : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ConsultationResponseDocumentService($objects, $files, $pdf, $l10n, new NullLogger());
	}

	/**
	 * The controller for a caller.
	 *
	 * @param bool   $admin    Whether the caller is an administrator.
	 * @param bool   $inScope  Whether the caller is in the works council's signatory scope.
	 * @param string $format   The requested format.
	 * @param bool   $signedIn Whether anyone is signed in.
	 *
	 * @return WorksCouncilConsultationController
	 */
	private function controller(bool $admin, bool $inScope, string $format = 'markdown', bool $signedIn = true): WorksCouncilConsultationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'format' ? $format : $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kees');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$scope = $this->createMock(GovernanceScopeGuard::class);
		$scope->method('isInBodyScope')->willReturnCallback(static fn (string $userId, string $bodyId, string $scope): bool => $inScope === true && $userId === 'kees' && $bodyId === self::BODY && $scope === 'signatory');

		return new WorksCouncilConsultationController($request, $session, $groups, $scope, $this->service());
	}

	/**
	 * An object entity double.
	 *
	 * @param array<string, mixed> $object The data.
	 * @param string               $uuid   The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $object, string $uuid): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($object);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}
}
