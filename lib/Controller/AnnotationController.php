<?php

/**
 * Decidiq Annotation Controller
 *
 * The annotation endpoints: a document's annotations, writing one, the counts
 * per agenda item and the author's own export and removal. Every read is
 * filtered by who may see each annotation and every change is the author's
 * alone; the checks run in AnnotationService and AnnotationVisibilityService,
 * called from each method here.
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
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\AnnotationRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\AnnotationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Annotation reads and writes behind the visibility and author checks.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
class AnnotationController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     HTTP request
	 * @param AnnotationService $annotations The annotation reads and writes
	 * @param IUserSession      $userSession User session
	 * @param LoggerInterface   $logger      Logger
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function __construct(
		IRequest $request,
		private readonly AnnotationService $annotations,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The annotations of a document the caller may see, every version.
	 *
	 * Query parameters are ignored: what is returned is decided by who asks.
	 *
	 * @param int $fileId The Nextcloud file id
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(int $fileId): JSONResponse {
		return $this->respond(
			status: Http::STATUS_OK,
			action: fn (string $userId): array => $this->annotations->listForDocument(fileId: $fileId, userId: $userId)
		);
	}//end index()

	/**
	 * Save an annotation on a document. The author and the document version
	 * come from the session and the file; private unless asked otherwise.
	 *
	 * @param int $fileId The Nextcloud file id
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function create(int $fileId): JSONResponse {
		$data = $this->bodyParams(request: $this->request, stripKeys: ['fileId', '_route']);

		return $this->respond(
			status: Http::STATUS_CREATED,
			action: fn (string $userId): array => ['annotation' => $this->annotations->create(fileId: $fileId, data: $data, userId: $userId)]
		);
	}//end create()

	/**
	 * Change an annotation's note, colour or visibility. Author only.
	 *
	 * @param string $id The annotation
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$data = $this->bodyParams(request: $this->request);

		return $this->respond(
			status: Http::STATUS_OK,
			action: fn (string $userId): array => ['annotation' => $this->annotations->update(id: $id, data: $data, userId: $userId)]
		);
	}//end update()

	/**
	 * Delete an annotation. Author only.
	 *
	 * @param string $id The annotation
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		return $this->respond(
			status: Http::STATUS_OK,
			action: function (string $userId) use ($id): array {
				$this->annotations->delete(id: $id, userId: $userId);
				return ['deleted' => true];
			}
		);
	}//end destroy()

	/**
	 * Per agenda item of a meeting, the number of annotations the caller may see.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function counts(string $meetingId): JSONResponse {
		return $this->respond(
			status: Http::STATUS_OK,
			action: fn (string $userId): array => ['counts' => (object)$this->annotations->countsForMeeting(meetingId: $meetingId, userId: $userId)]
		);
	}//end counts()

	/**
	 * Download the caller's own annotations, as JSON or CSV.
	 *
	 * @param string $format json or csv
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
	 *
	 * @return Response
	 */
	#[NoAdminRequired]
	public function export(string $format = 'json'): Response {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($format !== 'json' && $format !== 'csv') {
			return new JSONResponse(['message' => 'Format must be json or csv.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			if ($format === 'csv') {
				return $this->download(body: $this->annotations->exportCsv(userId: $userId), filename: 'annotations.csv', contentType: 'text/csv; charset=utf-8');
			}

			$json = json_encode(
				['annotations' => $this->annotations->exportRows(userId: $userId)],
				(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
			);

			return $this->download(body: $json, filename: 'annotations.json', contentType: 'application/json; charset=utf-8');
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: annotation export failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The annotations could not be exported.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end export()

	/**
	 * Delete every annotation the caller wrote, shared ones included.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function destroyOwn(): JSONResponse {
		return $this->respond(
			status: Http::STATUS_OK,
			action: fn (string $userId): array => ['deleted' => $this->annotations->deleteOwn(userId: $userId)]
		);
	}//end destroyOwn()

	/**
	 * A file download.
	 *
	 * @param string $body        The file content
	 * @param string $filename    The file name
	 * @param string $contentType The media type
	 *
	 * @return DataDisplayResponse
	 */
	private function download(string $body, string $filename, string $contentType): DataDisplayResponse {
		$response = new DataDisplayResponse($body, Http::STATUS_OK, ['Content-Type' => $contentType]);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

		return $response;
	}//end download()

	/**
	 * Run an annotation action for the signed-in user and map its outcome:
	 * 401 signed out, 403 not the author, 404 unknown or not visible, 422
	 * refused content, 500 failure.
	 *
	 * @param int                                      $status The success status
	 * @param callable(string): array<string, mixed> $action The action, given the user id
	 *
	 * @return JSONResponse
	 */
	private function respond(int $status, callable $action): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($action($userId), $status);
		} catch (AccessDeniedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (AnnotationRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: annotation request failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The annotation request failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end respond()
}//end class
