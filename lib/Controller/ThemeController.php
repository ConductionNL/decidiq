<?php

/**
 * Decidiq Theme Controller
 *
 * The staff timeline of a theme: every linked meeting, agenda item,
 * decision, commitment and long-term agenda item by its date, oldest first,
 * each marked published or not.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ThemeTimelineBuilder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the staff timeline of a theme the caller may read.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */
class ThemeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The request.
	 * @param IUserSession           $userSession   Who asks.
	 * @param ObjectServiceInterface $objectService OpenRegister's objects, read with the caller's rights.
	 * @param ThemeTimelineBuilder   $timeline      Turns the links into dated entries.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectServiceInterface $objectService,
		private readonly ThemeTimelineBuilder $timeline,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The theme's timeline for staff: every linked item, oldest first, with whether it is published.
	 *
	 * The theme is read with the caller's own rights, so a theme the caller
	 * may not read answers 404, the same as one that does not exist.
	 *
	 * @param string $id The theme UUID.
	 *
	 * @return JSONResponse 200 `{ entries }`, 401 signed out, 404 unknown or not readable.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	#[NoAdminRequired]
	public function timeline(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$theme = $this->readableTheme(id: $id);
		if ($theme === null) {
			return new JSONResponse(['message' => 'Theme not found'], Http::STATUS_NOT_FOUND);
		}

		$entries = [];
		foreach ($this->timeline->entries(theme: $theme) as $entry) {
			$entries[] = [
				'date' => $entry['date'],
				'kind' => $entry['kind'],
				'title' => $entry['title'],
				'objectId' => $entry['objectId'],
				'published' => $entry['published'],
				'publication' => $entry['publication'],
			];
		}

		return new JSONResponse(['entries' => $entries]);

	}//end timeline()

	/**
	 * The theme as data when the caller may read it, else null.
	 *
	 * @param string $id The theme UUID.
	 *
	 * @return array<string, mixed>|null The theme.
	 */
	private function readableTheme(string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: 'governance-theme');
		} catch (Throwable $e) {
			$this->logger->info('Decidiq theme timeline: the theme could not be read', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		if (is_array($data) === false) {
			return null;
		}

		return $data;

	}//end readableTheme()
}//end class
