<?php

/**
 * The public ORI feed endpoints.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\OriFeedController;
use OCA\Decidiq\Service\Ori\OriFeedService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(OriFeedController::class)]
final class OriFeedControllerTest extends TestCase {

	/**
	 * The three feeds.
	 *
	 * @return array<string, array{string}>
	 */
	public static function feeds(): array {
		return ['meetings' => ['meetings'], 'agenda items' => ['agendaItems'], 'documents' => ['documents']];
	}

	#[DataProvider('feeds')]
	public function testAFeedIsServedAsRss(string $feed): void {
		$service = $this->createMock(OriFeedService::class);
		$service->expects($this->once())->method($feed)->willReturn('<rss version="2.0"/>');
		$controller = new OriFeedController('decidiq', $this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class));

		$response = $controller->$feed();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('<rss version="2.0"/>', $response->render());
		// getHeaders() merges server-wide headers and needs a booted server; the header set here is what is asserted.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('application/rss+xml; charset=utf-8', $headers['Content-Type']);
	}

	#[DataProvider('feeds')]
	public function testAFeedIsPublicAndNeedsNoCsrfToken(string $feed): void {
		$method = new ReflectionMethod(OriFeedController::class, $feed);

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
	}

	public function testAFailingFeedAnswers500WithoutAPartialBody(): void {
		$service = $this->createMock(OriFeedService::class);
		$service->method('meetings')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$controller = new OriFeedController('decidiq', $this->createMock(IRequest::class), $service, $logger);

		$response = $controller->meetings();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('', $response->render());
	}
}
