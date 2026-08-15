<?php

/**
 * Unit tests for OriController — the ORI harvest feed over published payloads.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
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

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\OriController;
use OCA\Decidesk\Service\OriSerializer;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OriController `publications` ORI harvest resource.
 *
 * publish-decisions-via-opencatalogi task 5.2 — the harvest feed must surface a
 * PublicationPayload only inside its RBAC published-predicate window: visible
 * once `publicationDate <= now`, never before that date, and gone once
 * `depublicationDate` is in the past. The feed must self-declare each item's ORI
 * `@type` from the payload `oriType` and carry only the allow-list, PII-free
 * fields the payload schema constructs.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class OriControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IConfig.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var OriController
	 */
	private OriController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objectService);

		$this->config->method('getSystemValueString')->willReturn('https://gemeente.example');

		$this->controller = new OriController(
			$this->request,
			$this->config,
			$this->container,
			$this->logger,
			new OriSerializer(),
		);

	}//end setUp()

	/**
	 * Build a mock ObjectEntity returning $data from jsonSerialize().
	 *
	 * @param array<string,mixed> $data The serialized payload.
	 *
	 * @return object
	 */
	private function makeEntity(array $data): object {
		$entity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end makeEntity()

	/**
	 * A Besluit payload published in the past (and not depublished) appears on
	 * the harvest feed, self-declares oriType=Besluit, and carries no UID/PII.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testLivePublicationAppearsOnFeed(): void {
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeEntity(
					[
						'uuid' => 'pub-1',
						'oriType' => 'Besluit',
						'schemaOrgType' => 'ChooseAction',
						'title' => 'Vaststelling Programmabegroting 2026',
						'text' => 'De raad besluit.',
						'outcome' => 'adopted',
						'voteTotals' => ['for' => 30, 'against' => 5, 'abstain' => 0],
						'bodyName' => 'Gemeenteraad Amsterdam',
						'publicationDate' => $past,
					]
				),
			]
		);

		$response = $this->controller->index(resource: 'publications');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		self::assertSame(1, $body['count']);
		$item = $body['items'][0];

		self::assertSame('Besluit', $item['@type']);
		self::assertSame('Besluit', $item['oriType']);
		self::assertSame('pub-1', $item['id']);
		self::assertSame('Vaststelling Programmabegroting 2026', $item['name']);
		self::assertSame('adopted', $item['outcome']);
		self::assertSame(['for' => 30, 'against' => 5, 'abstain' => 0], $item['vote_totals']);
		self::assertSame('Gemeenteraad Amsterdam', $item['body']);

		// PII guard: a payload never carries voter identities, UIDs, or contact
		// details — the feed must not surface any such field.
		self::assertArrayNotHasKey('email', $item);
		self::assertArrayNotHasKey('@self', $item);
		self::assertArrayNotHasKey('uid', $item);

	}//end testLivePublicationAppearsOnFeed()

	/**
	 * A future-dated publication (publicationDate in the future) is NOT visible
	 * on the anonymous harvest feed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testFutureDatedPublicationIsHidden(): void {
		$future = (new \DateTimeImmutable('+10 days'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeEntity(
					[
						'uuid' => 'pub-future',
						'oriType' => 'Besluit',
						'title' => 'Embargoed decision',
						'publicationDate' => $future,
					]
				),
			]
		);

		$response = $this->controller->index(resource: 'publications');
		$body = $response->getData();

		self::assertSame(0, $body['count']);
		self::assertSame([], $body['items']);

	}//end testFutureDatedPublicationIsHidden()

	/**
	 * A depublished publication (depublicationDate in the past) is gone from the
	 * harvest feed even though publicationDate is in the past.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testDepublishedPublicationIsHidden(): void {
		$past = (new \DateTimeImmutable('-10 days'))->format(\DateTimeInterface::ATOM);
		$depublishPast = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeEntity(
					[
						'uuid' => 'pub-withdrawn',
						'oriType' => 'Verslag',
						'title' => 'Withdrawn minutes',
						'publicationDate' => $past,
						'depublicationDate' => $depublishPast,
					]
				),
			]
		);

		$response = $this->controller->index(resource: 'publications');
		$body = $response->getData();

		self::assertSame(0, $body['count']);

	}//end testDepublishedPublicationIsHidden()

	/**
	 * A scheduled depublication still in the future does not hide a live payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testFutureDepublicationStaysVisible(): void {
		$past = (new \DateTimeImmutable('-2 days'))->format(\DateTimeInterface::ATOM);
		$future = (new \DateTimeImmutable('+5 days'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeEntity(
					[
						'uuid' => 'pub-scheduled',
						'oriType' => 'Vergadering',
						'title' => 'Agenda with scheduled depublication',
						'meetingDate' => $past,
						'agendaItems' => [['oriType' => 'AgendaPunt', 'title' => 'Item 1']],
						'publicationDate' => $past,
						'depublicationDate' => $future,
					]
				),
			]
		);

		$response = $this->controller->index(resource: 'publications');
		$body = $response->getData();

		self::assertSame(1, $body['count']);
		$item = $body['items'][0];
		self::assertSame('Vergadering', $item['@type']);
		self::assertSame($past, $item['meeting_date']);
		self::assertSame([['oriType' => 'AgendaPunt', 'title' => 'Item 1']], $item['agenda_items']);

	}//end testFutureDepublicationStaysVisible()

	/**
	 * A payload with no publicationDate at all (never published) is not visible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testNeverPublishedPayloadIsHidden(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->makeEntity(
					[
						'uuid' => 'pub-draft',
						'oriType' => 'Besluit',
						'title' => 'Drafted but not published',
					]
				),
			]
		);

		$response = $this->controller->index(resource: 'publications');
		self::assertSame(0, $response->getData()['count']);

	}//end testNeverPublishedPayloadIsHidden()

	/**
	 * show() returns 404 for a future-dated payload (not 403 — never confirm an
	 * unpublished payload exists).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testShowFutureDatedPayloadIs404(): void {
		$future = (new \DateTimeImmutable('+3 days'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('find')->willReturn(
			$this->makeEntity(
				[
					'uuid' => 'pub-future',
					'oriType' => 'Besluit',
					'title' => 'Embargoed',
					'publicationDate' => $future,
				]
			)
		);

		$response = $this->controller->show(resource: 'publications', id: 'pub-future');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowFutureDatedPayloadIs404()

	/**
	 * show() returns 404 — not 500 — when OpenRegister's published-predicate RBAC
	 * hides the payload by making find() THROW instead of returning null.
	 *
	 * The test above only covers the case where the row comes back and the
	 * not-live branch rejects it. On a real instance the anonymous caller never
	 * gets the row at all: find() raises DoesNotExistException, which the blanket
	 * Throwable catch turned into HTTP 500. A 500 is itself a disclosure here — it
	 * separates "exists but hidden" from "unknown id", which is exactly what this
	 * endpoint must never confirm.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testShowIs404WhenFindThrowsDoesNotExist(): void {
		$this->objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException(
				"Object with identifier 'pub-hidden' not found in any magic table"
			)
		);

		$response = $this->controller->show(resource: 'publications', id: 'pub-hidden');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Not found', $response->getData()['message']);

	}//end testShowIs404WhenFindThrowsDoesNotExist()

	/**
	 * show() returns the ORI JSON-LD entity for a live payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
	 */
	public function testShowLivePayloadReturnsEntity(): void {
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

		$this->objectService->method('find')->willReturn(
			$this->makeEntity(
				[
					'uuid' => 'pub-live',
					'oriType' => 'Besluit',
					'title' => 'Live decision',
					'publicationDate' => $past,
				]
			)
		);

		$response = $this->controller->show(resource: 'publications', id: 'pub-live');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		self::assertSame('Besluit', $body['@type']);
		self::assertSame('pub-live', $body['id']);
		self::assertSame('Live decision', $body['name']);

	}//end testShowLivePayloadReturnsEntity()

	/**
	 * An unknown ORI resource slug returns 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 */
	public function testUnknownResourceIs404(): void {
		$response = $this->controller->index(resource: 'nonexistent');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownResourceIs404()

	/**
	 * Read the headers the controller itself set on a response.
	 *
	 * `Response::getHeaders()` merges in framework headers by asking
	 * `\OC::$server` for the request, and `\OC` does not exist in a standalone
	 * unit run. For a CORS preflight the status is not the contract, so this
	 * reads the private `headers` array that `Response::addHeader()` writes to.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response to inspect.
	 *
	 * @return array<string, string> The controller-set headers.
	 */
	private function controllerHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);

		return (array)$property->getValue($response);
	}//end controllerHeaders()

	/**
	 * The harvest-feed list preflight answers 200 with an empty body and the
	 * full CORS header triple.
	 *
	 * The ORI feed exists to be read cross-origin by harvesters and by
	 * browser-based catalogue clients; without these headers the preflight
	 * succeeds at the status line and the real GET is never sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	public function testPreflightReturnsCorsHeaders(): void {
		$response = $this->controller->preflight(resource: 'publications');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

		$headers = $this->controllerHeaders($response);
		self::assertSame('https://gemeente.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers']);

	}//end testPreflightReturnsCorsHeaders()

	/**
	 * The item preflight (`/api/ori/v1/{resource}/{id}`) carries the same
	 * header triple as the list preflight.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	public function testPreflightItemReturnsCorsHeaders(): void {
		$response = $this->controller->preflightItem(resource: 'publications', id: 'pub-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

		$headers = $this->controllerHeaders($response);
		self::assertSame('https://gemeente.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);

	}//end testPreflightItemReturnsCorsHeaders()

}//end class
