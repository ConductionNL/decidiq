<?php

/**
 * Unit tests for PaperController: a paper opened through decidiq, stamped
 * when the meeting asks for it.
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
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\PaperController;
use OCA\Decidiq\Service\PaperDelivery;
use OCA\Decidiq\Service\PaperLocator;
use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Service\AgendaAuthorizationGuard;
use OCA\Decidiq\Service\PaperStamper;
use OCA\Decidiq\Service\RestrictedPaperViews;
use OCA\Decidiq\Service\ClosedPaperReads;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Read rights first, then the paper must be the page's own, then the stamp;
 * a paper that must be stamped is never sent unstamped.
 *
 * @covers \OCA\Decidiq\Controller\PaperController
 * @covers \OCA\Decidiq\Service\PaperLocator
 * @covers \OCA\Decidiq\Service\PaperDelivery
 *
 * @uses \OCA\Decidiq\Exception\PaperStampRefusedException
 */
class PaperControllerTest extends TestCase {

	private const ITEM = '11111111-2222-4333-8444-555555555555';

	private const MEETING = '99999999-8888-4777-8666-555555555555';

	/**
	 * The item records paper 42 (PDF of Office file 41).
	 */
	/**
	 * The view log the next controller uses; null for one that records nothing.
	 *
	 * @var RestrictedPaperViews|null
	 */
	private ?RestrictedPaperViews $views = null;

	/**
	 * The closed-paper read log the next controller uses; null for one that records nothing.
	 *
	 * @var ClosedPaperReads|null
	 */
	private ?ClosedPaperReads $reads = null;

	private const ITEM_DATA = [
		'meeting'         => self::MEETING,
		'paperRenditions' => [['sourceFileId' => 41, 'pdfFileId' => 42, 'sourceName' => 'Grondtransactie.docx']],
	];

	/**
	 * Build the controller.
	 *
	 * @param PaperStamper              $stamper   The stamper double.
	 * @param ObjectEntityInterface|null $item     What the caller's read of the item answers.
	 * @param array<string, mixed>       $meeting  The meeting.
	 * @param array<int, int>|null      $findCalls Collects the item reads' rbac flag.
	 *
	 * @return PaperController
	 */
	private function controller(PaperStamper $stamper, ?ObjectEntityInterface $item, array $meeting=[], ?array &$findCalls=null): PaperController {
		$meetingEntity = $this->createMock(ObjectEntityInterface::class);
		$meetingEntity->method('getObject')->willReturn($meeting);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($item, $meetingEntity, &$findCalls): ?ObjectEntityInterface {
				// Positional, as the double receives them: id, options, _rbac, _multitenancy.
				$schema      = ($args[1] ?? null)?->schema;
				$findCalls[] = [$schema, $args[2] ?? true];
				// The meeting of an item is a system read; the caller's own read answers $item.
				if ($schema === 'meeting' && ($args[2] ?? true) === false) {
					return $meetingEntity;
				}

				return $item;
			}
		);

		$guard = $this->createMock(AgendaAuthorizationGuard::class);
		$guard->method('requireUser')->willReturn(null);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF raw');
		$file->method('getName')->willReturn('Grondtransactie.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getEtag')->willReturn('e1');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $id === 42 || $id === 41 ? $file : null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('aisha');
		$user->method('getDisplayName')->willReturn('Aisha Bakker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PaperController(
			$this->createMock(IRequest::class),
			$guard,
			new PaperDelivery(
				new PaperLocator(
					objectService: $objects,
					rootFolder: $root,
					logger: $this->createMock(originalClassName: LoggerInterface::class),
					mountCache: $this->noMounts()
				),
				$stamper,
				$this->createMock(LoggerInterface::class),
				$this->views ?? $this->createMock(RestrictedPaperViews::class),
				$this->reads ?? $this->createMock(ClosedPaperReads::class)
			),
			$objects,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The item as the caller may read it.
	 *
	 * @return ObjectEntityInterface
	 */
	private function item(): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(self::ITEM_DATA);
		return $entity;
	}//end item()

	/**
	 * A paper that must be stamped is served stamped for the reader, and the
	 * item is read under the caller's own rights.
	 *
	 * @return void
	 */
	public function testAPaperThatMustBeStampedIsServedStamped(): void {
		$meeting = ['isPublic' => false, 'watermarkText' => 'Vertrouwelijk'];
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->with($meeting, self::ITEM)->willReturn(true);
		$stamper->expects($this->once())->method('stamped')
			->with($meeting, ['fileId' => 42, 'etag' => 'e1', 'content' => '%PDF raw'], ['uid' => 'aisha', 'displayName' => 'Aisha Bakker'])
			->willReturn('%PDF stamped');

		$calls    = [];
		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: $meeting, findCalls: $calls)
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('%PDF stamped', $response->render());
		// getHeaders() reaches for the server container; the headers set here are the property.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('application/pdf', $headers['Content-Type']);
		self::assertSame('inline; filename="Grondtransactie.pdf"', $headers['Content-Disposition']);
		self::assertSame(['agenda-item', true], $calls[0]);
	}//end testAPaperThatMustBeStampedIsServedStamped()

	/**
	 * A paper of a public meeting without restriction is served as it is.
	 *
	 * @return void
	 */
	public function testAPaperThatNeedNotBeStampedIsServedAsItIs(): void {
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->willReturn(false);
		$stamper->expects($this->never())->method('stamped');

		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: ['isPublic' => true])
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame('%PDF raw', $response->render());
	}//end testAPaperThatNeedNotBeStampedIsServedAsItIs()

	/**
	 * A user who cannot read the item gets 404, and no paper is read.
	 *
	 * @return void
	 */
	public function testAUserWhoCannotReadTheItemGets404(): void {
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->never())->method('stamped');
		$stamper->expects($this->never())->method('mustStamp');

		$response = $this->controller(stamper: $stamper, item: null)->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAUserWhoCannotReadTheItemGets404()

	/**
	 * A file the item does not record is not served through it.
	 *
	 * @return void
	 */
	public function testAFileTheItemDoesNotRecordGets404(): void {
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->never())->method('stamped');

		$response = $this->controller(stamper: $stamper, item: $this->item())->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 7);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFileTheItemDoesNotRecordGets404()

	/**
	 * Without a stamping service: 503 with the reason, and no file.
	 *
	 * @return void
	 */
	public function testWithoutAStampingServiceItIs503AndNoFile(): void {
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->willReturn(true);
		$stamper->method('stamped')->willThrowException(
			new PaperStampRefusedException(PaperStampRefusedException::NO_SERVICE, 503)
		);

		$response = $this->controller(stamper: $stamper, item: $this->item())->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame(
			['message' => 'This paper can only be shown with a watermark, and the watermark service is not installed'],
			$response->getData()
		);
	}//end testWithoutAStampingServiceItIs503AndNoFile()

	/**
	 * Only meetings and agenda items carry papers.
	 *
	 * @return void
	 */
	public function testOtherRecordTypesAreRefused(): void {
		$response = $this->controller(stamper: $this->createMock(PaperStamper::class), item: $this->item())
			->view(schema: 'motion', objectId: self::ITEM, fileId: 42);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testOtherRecordTypesAreRefused()

	/**
	 * A meeting's own paper is judged on that meeting.
	 *
	 * @return void
	 */
	public function testAMeetingPaperIsJudgedOnTheMeeting(): void {
		$data   = ['isPublic' => true, 'watermarkPapers' => 'on', 'paperRenditions' => [['sourceFileId' => 41]]];
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->once())->method('mustStamp')->with($data, null)->willReturn(true);
		$stamper->method('stamped')->willReturn('%PDF stamped');

		$calls    = [];
		$response = $this->controller(stamper: $stamper, item: $entity, findCalls: $calls)->view(schema: 'meeting', objectId: self::MEETING, fileId: 41);

		self::assertSame('%PDF stamped', $response->render());
		self::assertSame([['meeting', true]], $calls);
	}//end testAMeetingPaperIsJudgedOnTheMeeting()

	/**
	 * The opening of a paper under an active restriction is recorded for the reader before the paper is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function testTheOpeningIsRecordedForTheReader(): void {
		$this->views = $this->createMock(RestrictedPaperViews::class);
		$this->views->expects($this->once())->method('record')->with('aisha', self::ITEM, 42)->willReturn(null);
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->willReturn(false);

		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: ['isPublic' => true])
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame('%PDF raw', $response->render());
	}//end testTheOpeningIsRecordedForTheReader()

	/**
	 * An opening that cannot be recorded is refused with 503, and the paper is not stamped or sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function testAnUnrecordedOpeningIsRefused(): void {
		$this->views = $this->createMock(RestrictedPaperViews::class);
		$this->views->method('record')->willReturn('This paper is not shown: the opening of a confidential paper could not be recorded.');
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->never())->method('mustStamp');
		$stamper->expects($this->never())->method('stamped');

		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: ['isPublic' => false])
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(503, $response->getStatus());
	}//end testAnUnrecordedOpeningIsRefused()

	/**
	 * A read of an item's paper reaches the closed-paper log with the reader, the item's data, the file and its name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-the-griffie-checks-who-read-the-paper
	 */
	public function testAnItemPaperReadReachesTheClosedPaperLog(): void {
		$this->reads = $this->createMock(ClosedPaperReads::class);
		$this->reads->expects($this->once())->method('record')->with(
			'aisha',
			$this->callback(static fn (array $paper): bool => $paper['agendaItemId'] === self::ITEM && $paper['fileId'] === 42 && $paper['name'] === 'Grondtransactie.pdf' && is_array($paper['item']))
		)->willReturn(null);
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->method('mustStamp')->willReturn(false);

		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: ['isPublic' => true])
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertInstanceOf(DataDisplayResponse::class, $response);
	}//end testAnItemPaperReadReachesTheClosedPaperLog()

	/**
	 * An unrecorded read of a closed paper is refused with 503.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
	 */
	public function testAnUnrecordedClosedPaperReadIsRefused(): void {
		$this->reads = $this->createMock(ClosedPaperReads::class);
		$this->reads->method('record')->willReturn('This paper is not shown: the read of a closed paper could not be recorded.');
		$stamper = $this->createMock(PaperStamper::class);
		$stamper->expects($this->never())->method('stamped');

		$response = $this->controller(stamper: $stamper, item: $this->item(), meeting: ['isPublic' => true])
			->view(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertSame(503, $response->getStatus());
	}//end testAnUnrecordedClosedPaperReadIsRefused()

	/**
	 * A mount cache that knows no further mounts.
	 *
	 * @return IUserMountCache
	 */
	private function noMounts(): IUserMountCache {
		$mounts = $this->createMock(originalClassName: IUserMountCache::class);
		$mounts->method('getMountsForFileId')->willReturn(value: []);
		return $mounts;
	}//end noMounts()
}//end class
