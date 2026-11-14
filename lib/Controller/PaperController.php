<?php

/**
 * Decidiq Paper Controller
 *
 * GET /api/papers/{schema}/{objectId}/{fileId}/view opens one paper of a
 * meeting or an agenda item. The caller must be able to read that meeting or
 * item under their own rights (404 otherwise), and the file must be one of its
 * papers. When the meeting's watermark policy applies, the paper is served
 * only stamped with the reader's name and the date; when it cannot be stamped
 * the request is refused with the reason and no file is sent.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\AgendaAuthorizationGuardInterface;
use OCA\Decidiq\Service\PaperStamper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Opens a paper, stamped when the meeting asks for it.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                          $request       The request
	 * @param ObjectServiceInterface            $objectService The OpenRegister object service
	 * @param AgendaAuthorizationGuardInterface $guard         Requires a signed-in user
	 * @param PaperStamper                      $stamper       The watermark policy and stamp
	 * @param IRootFolder                       $rootFolder    Reads the paper
	 * @param IUserSession                      $userSession   The reader
	 * @param IL10N                             $l10n          Translates the refusals
	 * @param LoggerInterface                   $logger        Logger
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectServiceInterface $objectService,
		private readonly AgendaAuthorizationGuardInterface $guard,
		private readonly PaperStamper $stamper,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open one paper of a meeting or an agenda item.
	 *
	 * @param string $schema   `agenda-item` or `meeting`
	 * @param string $objectId The object uuid
	 * @param int    $fileId   The paper's file id
	 *
	 * @return DataDisplayResponse|JSONResponse
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(string $schema, string $objectId, int $fileId): DataDisplayResponse|JSONResponse {
		$denied = $this->guard->requireUser();
		if ($denied !== null) {
			return $denied;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($schema, ['agenda-item', 'meeting'], true) === false) {
			return $this->message(text: 'Papers are opened from meetings and agenda items only.', status: Http::STATUS_BAD_REQUEST);
		}

		$data = $this->readable(schema: $schema, objectId: $objectId);
		if ($data === null || $this->recordsPaper(data: $data, fileId: $fileId) === false) {
			return $this->message(text: 'Not found.', status: Http::STATUS_NOT_FOUND);
		}

		$file = $this->rootFolder->getFirstNodeById($fileId);
		if (($file instanceof File) === false) {
			return $this->message(text: 'Not found.', status: Http::STATUS_NOT_FOUND);
		}

		$agendaItemId = null;
		$meeting      = $data;
		if ($schema === 'agenda-item') {
			$agendaItemId = $objectId;
			$meeting      = $this->meetingOf(item: $data);
		}

		try {
			if ($this->stamper->mustStamp(meeting: $meeting, agendaItemId: $agendaItemId) === false) {
				return $this->inline(content: $file->getContent(), name: $file->getName(), mimeType: $file->getMimetype());
			}

			$stamped = $this->stamper->stamped(
				meeting: $meeting,
				paper: ['fileId' => $fileId, 'etag' => (string)$file->getEtag(), 'content' => $file->getContent()],
				reader: ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()]
			);
		} catch (PaperStampRefusedException $e) {
			return $this->message(text: $e->getMessage(), status: $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: a paper could not be opened', ['fileId' => $fileId, 'exception' => $e->getMessage()]);
			return $this->message(text: 'The paper could not be opened.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->inline(content: $stamped, name: $file->getName(), mimeType: 'application/pdf');
	}//end view()

	/**
	 * The object as the caller may read it, or null.
	 *
	 * @param string $schema   `agenda-item` or `meeting`
	 * @param string $objectId The object uuid
	 *
	 * @return array<string, mixed>|null
	 */
	private function readable(string $schema, string $objectId): ?array {
		try {
			// Under the caller's own rights: a reader who may not see the
			// item or meeting gets the same 404 as a missing one.
			$object = $this->objectService->find(id: $objectId, register: 'decidiq', schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: paper refused, object not readable', ['objectId' => $objectId, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->getObject();
	}//end readable()

	/**
	 * The meeting of an agenda item the caller may read, or an empty meeting.
	 *
	 * An empty meeting is not public, so the paper is stamped: a meeting that
	 * cannot be read never lets a paper out unstamped.
	 *
	 * @param array<string, mixed> $item The agenda item
	 *
	 * @return array<string, mixed>
	 */
	private function meetingOf(array $item): array {
		$meetingId = trim((string)($item['meeting'] ?? ''));
		if ($meetingId === '') {
			return [];
		}

		try {
			$meeting = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: meeting of a paper unreadable; stamping it', ['meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			return [];
		}

		if ($meeting === null) {
			return [];
		}

		return $meeting->getObject();
	}//end meetingOf()

	/**
	 * Whether the object records the paper, as its PDF or as its original.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param int                  $fileId The file id
	 *
	 * @return bool
	 */
	private function recordsPaper(array $data, int $fileId): bool {
		foreach ((array)($data['paperRenditions'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ((int)($entry['pdfFileId'] ?? 0) === $fileId || (int)($entry['sourceFileId'] ?? 0) === $fileId) {
				return true;
			}
		}

		return false;
	}//end recordsPaper()

	/**
	 * The paper, shown in the browser.
	 *
	 * @param string $content  The bytes
	 * @param string $name     The file name
	 * @param string $mimeType The content type
	 *
	 * @return DataDisplayResponse
	 */
	private function inline(string $content, string $name, string $mimeType): DataDisplayResponse {
		$safe = str_replace(['"', '\\', "\r", "\n"], '', $name);
		$response = new DataDisplayResponse(
			$content,
			Http::STATUS_OK,
			['Content-Type' => $mimeType, 'Cache-Control' => 'private, no-store']
		);
		// The constructor sets an empty file name; this one names the paper.
		$response->addHeader('Content-Disposition', 'inline; filename="' . $safe . '"');

		return $response;
	}//end inline()

	/**
	 * A translated message.
	 *
	 * @param string $text   The English source string
	 * @param int    $status The HTTP status
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(['message' => $this->l10n->t($text)], $status);
	}//end message()
}//end class
