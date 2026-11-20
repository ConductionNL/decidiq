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
use OCA\Decidiq\Service\PaperLocator;
use OCA\Decidiq\Service\PaperStamper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
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
	 * @param IRequest                          $request     The request
	 * @param AgendaAuthorizationGuardInterface $guard       Requires a signed-in user
	 * @param PaperStamper                      $stamper     The watermark policy and stamp
	 * @param PaperLocator                      $locator     Finds the paper and its meeting
	 * @param IUserSession                      $userSession The reader
	 * @param IL10N                             $l10n        Translates the refusals
	 * @param LoggerInterface                   $logger      Logger
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function __construct(
		IRequest $request,
		private readonly AgendaAuthorizationGuardInterface $guard,
		private readonly PaperStamper $stamper,
		private readonly PaperLocator $locator,
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

		$paper = $this->locator->locate(schema: $schema, objectId: $objectId, fileId: $fileId);
		if ($paper === null) {
			return $this->message(text: 'Not found.', status: Http::STATUS_NOT_FOUND);
		}

		return $this->open(paper: $paper, reader: ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()]);
	}//end view()

	/**
	 * Serve a located paper: as it is, or stamped when the meeting asks for it.
	 *
	 * @param array{meeting: array<string, mixed>, agendaItemId: ?string, file: File, fileId: int} $paper  The located paper
	 * @param array{uid: string, displayName: string}                                            $reader The reader
	 *
	 * @return DataDisplayResponse|JSONResponse
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	private function open(array $paper, array $reader): DataDisplayResponse|JSONResponse {
		$file = $paper['file'];
		try {
			if ($this->stamper->mustStamp(meeting: $paper['meeting'], agendaItemId: $paper['agendaItemId']) === false) {
				return $this->inline(content: $file->getContent(), name: $file->getName(), mimeType: $file->getMimetype());
			}

			$stamped = $this->stamper->stamped(
				meeting: $paper['meeting'],
				paper: ['fileId' => $paper['fileId'], 'etag' => (string)$file->getEtag(), 'content' => $file->getContent()],
				reader: $reader
			);
		} catch (PaperStampRefusedException $e) {
			return $this->message(text: $e->getMessage(), status: $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: a paper could not be opened', ['fileId' => $paper['fileId'], 'exception' => $e->getMessage()]);
			return $this->message(text: 'The paper could not be opened.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->inline(content: $stamped, name: $file->getName(), mimeType: 'application/pdf');
	}//end open()

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
