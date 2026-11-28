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
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\AgendaAuthorizationGuardInterface;
use OCA\Decidiq\Service\PaperDelivery;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Opens a paper, stamped when the meeting asks for it.
 *
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                          $request       The request
	 * @param AgendaAuthorizationGuardInterface $guard         Requires a signed-in user
	 * @param PaperDelivery                     $delivery      Serves the paper, stamped when it must be
	 * @param ObjectServiceInterface            $objectService Reads the object under the caller's rights
	 * @param IUserSession                      $userSession   The reader
	 * @param IL10N                             $l10n          Translates the refusals
	 * @param LoggerInterface                   $logger        Logger
	 *
	 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function __construct(
		IRequest $request,
		private readonly AgendaAuthorizationGuardInterface $guard,
		private readonly PaperDelivery $delivery,
		private readonly ObjectServiceInterface $objectService,
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
	 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
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
		if ($data === null) {
			return $this->message(text: 'Not found.', status: Http::STATUS_NOT_FOUND);
		}

		$delivered = $this->delivery->deliver(
			located: ['schema' => $schema, 'objectId' => $objectId, 'data' => $data, 'fileId' => $fileId],
			reader: ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()]
		);
		if (isset($delivered['refusal']) === true) {
			return $this->message(text: $delivered['refusal'], status: $delivered['status']);
		}

		return $this->inline(content: $delivered['content'], name: $delivered['name'], mimeType: $delivered['mimeType']);
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
			$object = $this->objectService->find(id: $objectId, options: new ObjectFindOptions(register: 'decidiq', schema: $schema));
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
