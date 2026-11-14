<?php

/**
 * Public RSS feeds of council information in the ORI shape.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Service\Feed\RssDocument;
use OCA\Decidiq\Service\Ori\OriFeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET-only, anonymous, read-only: meetings, agenda items and documents of
 * public meetings, rendered by OriFeedService. A failure answers 500 with an
 * empty body rather than a partial feed.
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class OriFeedController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName The app name.
	 * @param IRequest        $request The request.
	 * @param OriFeedService  $feeds   Renders the feeds.
	 * @param LoggerInterface $logger  Logs a failed feed.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OriFeedService $feeds,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The public meetings.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function meetings(): DataDisplayResponse {
		return $this->feed(render: fn (): string => $this->feeds->meetings());
	}//end meetings()

	/**
	 * The agenda items of the public meetings.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function agendaItems(): DataDisplayResponse {
		return $this->feed(render: fn (): string => $this->feeds->agendaItems());
	}//end agendaItems()

	/**
	 * The documents of the public meetings.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function documents(): DataDisplayResponse {
		return $this->feed(render: fn (): string => $this->feeds->documents());
	}//end documents()

	/**
	 * Render a feed as an RSS response.
	 *
	 * @param callable(): string $render Renders the feed.
	 *
	 * @return DataDisplayResponse
	 */
	private function feed(callable $render): DataDisplayResponse {
		try {
			return new DataDisplayResponse(data: $render(), statusCode: Http::STATUS_OK, headers: ['Content-Type' => RssDocument::CONTENT_TYPE]);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Decidiq: ORI feed failed', context: ['exception' => $e]);
			return new DataDisplayResponse(data: '', statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end feed()
}//end class
