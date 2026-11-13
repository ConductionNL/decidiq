<?php

/**
 * Publishing a closed voting round to the configured ORI endpoint, and reporting whether it was.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DomainException;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\OriPublicationService
 */
class OriPublicationServiceTest extends TestCase {

	/**
	 * Every POST: [url, options].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $posts = [];

	/**
	 * Every save, as named arguments.
	 *
	 * @var list<array>
	 */
	private array $saved = [];

	/**
	 * What was logged: "level message".
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * The stored round, keyed by id.
	 *
	 * @var array<string, array>
	 */
	private array $rounds = [];

	/**
	 * The service.
	 *
	 * @param array<string, string> $config      App config values.
	 * @param mixed                 $eligibility The eligibility service, or a throwable the container throws.
	 * @param \Throwable|null       $postFails   What the POST throws, if anything.
	 *
	 * @return OriPublicationService The service.
	 */
	private function service(array $config, mixed $eligibility=null, ?\Throwable $postFails=null): OriPublicationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($eligibility): object {
				if ($eligibility instanceof \Throwable || $eligibility === null) {
					throw ($eligibility ?? new RuntimeException('not registered'));
				}

				return $eligibility;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($app === 'decidiq' ? ($config[$key] ?? $default) : $default)
		);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($postFails): IResponse {
				if ($postFails !== null) {
					throw $postFails;
				}

				$this->posts[] = [$url, $options];
				return $this->createMock(IResponse::class);
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logged[] = $level . ' ' . $message;
			});
		}

		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('find')->willReturnCallback(function (string|int $id): ?ObjectEntity {
			if (isset($this->rounds[$id]) === false) {
				return null;
			}

			$entity = new ObjectEntity();
			$entity->setObject($this->rounds[$id]);

			return $entity;
		});
		$store->method('saveObject')->willReturnCallback(
			function (mixed $object, mixed $extend=[], mixed $register=null, mixed $schema=null, mixed $uuid=null): ObjectEntity {
				$this->saved[] = compact('object', 'register', 'schema', 'uuid');
				return new ObjectEntity();
			}
		);

		return new OriPublicationService($container, $appConfig, $clients, $logger, $store);
	}//end service()

	/**
	 * A round is posted as JSON-LD with the bearer token, then stamped as published.
	 *
	 * @return void
	 */
	public function testARoundIsPostedAsJsonLdAndStamped(): void {
		$this->rounds['r-1'] = [
			'openedAt' => '2026-09-01T10:00:00Z',
			'closedAt' => '2026-09-01T10:05:00Z',
			'result' => 'adopted',
			'votesFor' => 9,
			'votesAgainst' => 2,
		];

		$this->service(['ori_endpoint' => 'https://ori.example.org/api', 'ori_bearer_secret' => 'tok'])->publish('r-1');

		self::assertCount(1, $this->posts);
		[$url, $options] = $this->posts[0];
		self::assertSame('https://ori.example.org/api', $url);
		self::assertSame(
			['Content-Type' => 'application/ld+json', 'Accept' => 'application/json', 'Authorization' => 'Bearer tok'],
			$options['headers']
		);
		self::assertSame(10, $options['timeout']);
		self::assertSame(
			[
				'@context' => 'https://schema.org/',
				'@type' => 'VoteAction',
				'@id' => 'urn:voting-round:r-1',
				'name' => 'Stemuitslag r-1',
				'startTime' => '2026-09-01T10:00:00Z',
				'endTime' => '2026-09-01T10:05:00Z',
				'result' => 'adopted',
				'object' => [
					'@type' => 'GovernmentPermit',
					'votesFor' => 9,
					'votesAgainst' => 2,
					'votesAbstain' => 0,
					'votingMethod' => 'for-against-abstain',
					'isSecret' => false,
					'quorumWith' => false,
				],
			],
			json_decode($options['body'], true)
		);

		self::assertCount(1, $this->saved);
		self::assertSame('decidiq', $this->saved[0]['register']);
		self::assertSame('voting-round', $this->saved[0]['schema']);
		self::assertSame('r-1', $this->saved[0]['uuid']);
		self::assertSame('adopted', $this->saved[0]['object']['result'], 'the round is merged, not replaced');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->saved[0]['object']['oriPublishedAt']);
		self::assertSame(['info Decidiq ORI: VotingRound r-1 published successfully to https://ori.example.org/api'], $this->logged);
	}//end testARoundIsPostedAsJsonLdAndStamped()

	/**
	 * Without a bearer secret no Authorization header is sent.
	 *
	 * @return void
	 */
	public function testWithoutASecretNoAuthorizationIsSent(): void {
		$this->rounds['r-2'] = [];

		$this->service(['ori_endpoint' => 'https://203.0.113.9/ori'])->publish('r-2');

		self::assertArrayNotHasKey('Authorization', $this->posts[0][1]['headers']);
	}//end testWithoutASecretNoAuthorizationIsSent()

	/**
	 * Without an endpoint, or with one that is refused, nothing is sent.
	 *
	 * @return void
	 */
	public function testNothingIsSentToAMissingOrRefusedEndpoint(): void {
		$this->rounds['r-3'] = [];

		$this->service([])->publish('r-3');
		foreach (['http://ori.example.org', 'https://', 'https://127.0.0.1/x', 'https://10.1.2.3/x'] as $endpoint) {
			$this->service(['ori_endpoint' => $endpoint])->publish('r-3');
		}

		self::assertSame([], $this->posts);
		self::assertSame([], $this->saved);
		self::assertCount(4, $this->logged);
		self::assertSame("warning Decidiq ORI: endpoint 'http://ori.example.org' failed safety validation — publication skipped", $this->logged[0]);
	}//end testNothingIsSentToAMissingOrRefusedEndpoint()

	/**
	 * A round type on the deny-list is refused before anything is read.
	 *
	 * @return void
	 */
	public function testTheDenyListRefusesBeforeAnythingIsRead(): void {
		$eligibility = $this->createMock(PublicationEligibilityService::class);
		$eligibility->expects($this->once())->method('assertPublishable')->with('voting-round')
			->willThrowException(new DomainException('never publishable'));
		$this->rounds['r-4'] = [];

		$this->service(['ori_endpoint' => 'https://ori.example.org'], $eligibility)->publish('r-4');

		self::assertSame([], $this->posts);
		self::assertSame(['warning Decidiq ORI: publication refused by deny-list — never publishable'], $this->logged);
	}//end testTheDenyListRefusesBeforeAnythingIsRead()

	/**
	 * A permitted type, or an unavailable eligibility service, publishes as usual.
	 *
	 * @return void
	 */
	public function testAPermittedTypeIsPublished(): void {
		$eligibility = $this->createMock(PublicationEligibilityService::class);
		$this->rounds['r-5'] = [];

		$this->service(['ori_endpoint' => 'https://ori.example.org'], $eligibility)->publish('r-5');
		$this->service(['ori_endpoint' => 'https://ori.example.org'], new RuntimeException('old container'))->publish('r-5');

		self::assertCount(2, $this->posts);
	}//end testAPermittedTypeIsPublished()

	/**
	 * A missing round is logged and not posted.
	 *
	 * @return void
	 */
	public function testAMissingRoundIsLoggedAndNotPosted(): void {
		$this->service(['ori_endpoint' => 'https://ori.example.org'])->publish('gone');

		self::assertSame([], $this->posts);
		self::assertSame(['warning Decidiq ORI: VotingRound gone not found for publication'], $this->logged);
	}//end testAMissingRoundIsLoggedAndNotPosted()

	/**
	 * A failed POST is logged and rethrown, and the round is not stamped.
	 *
	 * @return void
	 */
	public function testAFailedPostIsLoggedAndRethrown(): void {
		$this->rounds['r-6'] = [];
		$service = $this->service(['ori_endpoint' => 'https://ori.example.org'], null, new RuntimeException('503'));

		try {
			$service->publish('r-6');
			self::fail('the failure must reach the caller');
		} catch (RuntimeException $e) {
			self::assertSame('503', $e->getMessage());
		}

		self::assertSame([], $this->saved);
		self::assertSame(['warning Decidiq ORI: Publication error for round r-6: 503'], $this->logged);
	}//end testAFailedPostIsLoggedAndRethrown()

	/**
	 * The status is not configured, pending or published.
	 *
	 * @return void
	 */
	public function testThePublicationStatus(): void {
		$this->rounds['done'] = ['oriPublishedAt' => '2026-09-01T10:06:00+00:00'];
		$this->rounds['waiting'] = ['closedAt' => '2026-09-01T10:05:00Z'];

		self::assertSame('not_configured', $this->service([])->getPublicationStatus('done'));

		$service = $this->service(['ori_endpoint' => 'https://ori.example.org']);
		self::assertSame('published', $service->getPublicationStatus('done'));
		self::assertSame('pending', $service->getPublicationStatus('waiting'));
		self::assertSame('pending', $service->getPublicationStatus('gone'));
	}//end testThePublicationStatus()
}//end class
