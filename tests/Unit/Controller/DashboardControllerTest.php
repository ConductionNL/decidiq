<?php

/**
 * Wire-contract tests for the decidesk SPA host routes.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Controller\DashboardController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `GET /` (dashboard#page) and the Vue history-mode
 * catch-all (dashboard#catchAll).
 *
 * These two routes are the only way a browser ever reaches the app, and their
 * contract is narrow but load-bearing: a TemplateResponse for the decidesk app
 * naming the `index` template. Rendering a different template — or the right
 * template under the wrong app id — serves a blank page while every status-only
 * probe still reads 200.
 *
 * The catch-all matters separately from the page route: it is what makes a deep
 * link like `/apps/decidesk/meetings/<uuid>` survive a browser reload, and a
 * catch-all that diverged from `page()` would break exactly the reload path and
 * nothing a click-through test walks.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class DashboardControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var DashboardController
	 */
	private DashboardController $controller;

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller = new DashboardController($this->createMock(IRequest::class));

	}//end setUp()

	/**
	 * page() renders the decidesk `index` template with HTTP 200.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testPageRendersIndexTemplate(): void {
		$response = $this->controller->page();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
		self::assertSame(Application::APP_ID, $response->getApp());

	}//end testPageRendersIndexTemplate()

	/**
	 * catchAll() serves the same template as page() so a reloaded deep link
	 * boots the SPA instead of 404ing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testCatchAllServesTheSameSpaShell(): void {
		$response = $this->controller->catchAll();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
		self::assertSame(Application::APP_ID, $response->getApp());
		self::assertSame($this->controller->page()->getTemplateName(), $response->getTemplateName());

	}//end testCatchAllServesTheSameSpaShell()

}//end class
