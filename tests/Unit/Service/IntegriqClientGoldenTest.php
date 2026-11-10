<?php
/**
 * Golden test for the two integriq-backed clients.
 *
 * Runs StreamingClient and CaseSystemClient over the same connection and answer
 * shapes and records, by parameter name, every object-service and call-service
 * call they make, with what they return or throw. The expected values live in
 * tests/fixtures/integriq-client-golden.json; run with
 * RECORD_INTEGRIQ_CLIENT_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Service\CaseSystemClient;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\StreamingClient;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Golden test for StreamingClient and CaseSystemClient.
 *
 * @covers \OCA\Decidiq\Service\StreamingClient
 * @covers \OCA\Decidiq\Service\CaseSystemClient
 * @uses   \OCA\Decidiq\Service\SigningAnswer
 * @uses   \OCA\Decidiq\Service\IntegriqConnection
 * @uses   \OCA\Decidiq\Support\FleetAppId
 * @uses   \OCA\Decidiq\Exception\BroadcastRefusedException
 * @uses   \OCA\Decidiq\Exception\CaseSystemException
 */
class IntegriqClientGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/integriq-client-golden.json';

	/**
	 * Every recorded call of the current run.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Source id per entity find() handed out.
	 *
	 * @var array<int, string>
	 */
	private array $sourceIds = [];

	/**
	 * Connection-row shapes the object service can answer with.
	 *
	 * @return array<string, mixed> Name to findAll() answer, or an exception to throw.
	 */
	private function rowShapes(): array {
		$entity = new class {
			/**
			 * The row data.
			 *
			 * @return array<string, string> The data.
			 */
			public function getObject(): array {
				return ['source' => 'src-entity'];
			}//end getObject()
		};

		return [
			'none'            => ['results' => []],
			'bare list'       => [['source' => 'src-list']],
			'blank then real' => ['results' => [['source' => ''], ['other' => 1], ['source' => 'src-second']]],
			'entity row'      => ['results' => [$entity]],
			'object row'      => ['results' => [(object) ['source' => 'src-object']]],
			'two linked'      => ['results' => [['source' => 'src-first'], ['source' => 'src-last']]],
			'throws'          => new RuntimeException('register down'),
		];
	}//end rowShapes()

	/**
	 * Call-service answer shapes.
	 *
	 * @return array<string, mixed> Name to answer, or an exception to throw.
	 */
	private function answerShapes(): array {
		$withResponse = static fn (array $raw): object => new class($raw) {
			/**
			 * Remember the raw response.
			 *
			 * @param array<string, mixed> $raw The response.
			 */
			public function __construct(private readonly array $raw) {
			}//end __construct()

			/**
			 * The raw response.
			 *
			 * @return array<string, mixed> The response.
			 */
			public function getResponse(): array {
				return $this->raw;
			}//end getResponse()
		};
		$withObject   = static fn (array $data): object => new class($data) {
			/**
			 * Remember the object data.
			 *
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private readonly array $data) {
			}//end __construct()

			/**
			 * The object data.
			 *
			 * @return array<string, mixed> The data.
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()
		};

		return [
			'ok response'      => $withResponse(['statusCode' => 200, 'body' => '{"url":"u1","identification":"Z-1","title":"T","message":"m"}']),
			'no status'        => $withResponse(['body' => '{"url":"u2"}']),
			'error status'     => $withResponse(['statusCode' => 503, 'body' => '{"message":"busy"}']),
			'error no message' => $withResponse(['statusCode' => 404, 'body' => 'not json']),
			'object ok'        => $withObject(['response' => ['statusCode' => 201, 'body' => '{"url":"u3"}']]),
			'object error'     => $withObject(['response' => ['statusCode' => 400, 'body' => '[1,2]']]),
			'object empty'     => $withObject([]),
			'both shapes'      => new class {
				/**
				 * The raw response, which wins.
				 *
				 * @return array<string, mixed> The response.
				 */
				public function getResponse(): array {
					return ['statusCode' => 202, 'body' => '{"url":"u4"}'];
				}//end getResponse()

				/**
				 * Object data naming a failing status.
				 *
				 * @return array<string, mixed> The data.
				 */
				public function getObject(): array {
					return ['response' => ['statusCode' => 500]];
				}//end getObject()
			},
			'scalar'           => 'just a string',
			'throws'           => new RuntimeException('timeout'),
		];
	}//end answerShapes()

	/**
	 * An object service answering findAll() with one shape and find() with a source.
	 *
	 * @param mixed $rows The findAll() answer, or a Throwable to throw.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function objects(mixed $rows): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (...$args) use ($rows) {
				$this->log[] = ['findAll' => $this->named(method: 'findAll', args: $args)];
				if ($rows instanceof Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (...$args) {
				$named       = $this->named(method: 'find', args: $args);
				$this->log[] = ['find' => $named];
				$entity      = $this->createMock(ObjectEntityInterface::class);
				$this->sourceIds[spl_object_id($entity)] = (string) ($named['id'] ?? '');
				return $entity;
			}
		);
		return $objects;
	}//end objects()

	/**
	 * Name the arguments of an object-service call, leaving out those at their default.
	 *
	 * @param string            $method The ObjectServiceInterface method.
	 * @param array<int, mixed> $args   The arguments as the mock received them.
	 *
	 * @return array<string, mixed> Parameter name to value.
	 */
	private function namedAsDeclared(string $method, array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectServiceInterface::class, $method))->getParameters() as $i => $parameter) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $parameter->getDefaultValue() === $args[$i]) {
				continue;
			}

			$named[$parameter->getName()] = $args[$i];
		}

		return $named;
	}//end namedAsDeclared()

	/**
	 * The arguments by name; a saveObject() call is expanded back into its old
	 * parameter names, so the recording describes the write, not the call shape.
	 *
	 * @param mixed $method See namedAsDeclared()
	 * @param mixed $args See namedAsDeclared()
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$named = $this->namedAsDeclared($method, $args);
		if ($method === 'saveObject') {
			return SaveCallShape::legacyNamed(named: $named);
		}

		return $named;
	}//end named()

	/**
	 * A container whose integriq call service answers with one shape.
	 *
	 * @param mixed $answer The call answer, a Throwable to throw, or null for no call service.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(mixed $answer): ContainerInterface {
		$record = function (array $call): void {
			$call['source'] = $this->sourceIds[spl_object_id($call['source'])] ?? 'unknown';
			$this->log[]    = ['call' => $call];
		};
		$caller = new class($answer, $record) {
			/**
			 * Remember the answer and the recorder.
			 *
			 * @param mixed    $answer The answer.
			 * @param callable $record Records one call.
			 */
			public function __construct(private readonly mixed $answer, private readonly mixed $record) {
			}//end __construct()

			/**
			 * Record the call and answer it.
			 *
			 * @param object $source   The source.
			 * @param string $endpoint The endpoint.
			 * @param string $method   The method.
			 * @param array  $config   The config.
			 *
			 * @return mixed The answer.
			 */
			public function call(object $source, string $endpoint, string $method, array $config): mixed {
				($this->record)(['source' => $source, 'endpoint' => $endpoint, 'method' => $method, 'config' => $config]);
				if ($this->answer instanceof Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end call()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($caller, $answer) {
				if ($answer === null || str_contains($id, 'Integriq') === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $caller;
			}
		);
		return $container;
	}//end container()

	/**
	 * Run one operation and describe its outcome.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return array<string, mixed> The log and the result or exception.
	 */
	private function outcome(callable $operation): array {
		$this->log = [];
		try {
			$result = ['returned' => $operation()];
		} catch (Throwable $e) {
			$result = [
				'threw'    => get_class($e),
				'message'  => $e->getMessage(),
				'status'   => method_exists($e, 'getStatus') === true ? $e->getStatus() : null,
				'previous' => $e->getPrevious()?->getMessage(),
			];
		}

		return ['calls' => $this->log, 'result' => $result];
	}//end outcome()

	/**
	 * Both clients over every connection and answer shape.
	 *
	 * @return array<string, mixed> The observed behaviour.
	 */
	private function observe(): array {
		$observed = [];
		foreach ($this->rowShapes() as $rowName => $rows) {
			foreach ([StreamingClient::class, CaseSystemClient::class] as $class) {
				$client = new $class(objectService: $this->objects(rows: $rows), container: $this->container(answer: 'unused'), answers: new SigningAnswer());
				$observed[$class]['isConnected'][$rowName] = $this->outcome(operation: static fn () => $client->isConnected());
			}

			$client = new StreamingClient(objectService: $this->objects(rows: $rows), container: $this->container(answer: 'unused'), answers: new SigningAnswer());
			$observed[StreamingClient::class]['requireConnected'][$rowName] = $this->outcome(operation: static fn () => $client->requireConnected());
		}

		$rows = $this->rowShapes()['blank then real'];
		foreach (array_merge($this->answerShapes(), ['no call service' => null]) as $answerName => $answer) {
			$streaming = new StreamingClient(objectService: $this->objects(rows: $rows), container: $this->container(answer: $answer), answers: new SigningAnswer());
			$observed[StreamingClient::class]['call'][$answerName] = $this->outcome(operation: static fn () => $streaming->call(operation: 'start', body: ['meeting' => 'm1']));

			$case = new CaseSystemClient(objectService: $this->objects(rows: $rows), container: $this->container(answer: $answer), answers: new SigningAnswer());
			$observed[CaseSystemClient::class]['readCase'][$answerName] = $this->outcome(operation: static fn () => $case->readCase(reference: 'Z-1'));
		}

		foreach (['none', 'throws'] as $rowName) {
			foreach ([StreamingClient::class, CaseSystemClient::class] as $class) {
				$client = new $class(objectService: $this->objects(rows: $this->rowShapes()[$rowName]), container: $this->container(answer: 'unused'), answers: new SigningAnswer());
				$run    = $class === StreamingClient::class
					? static fn () => $client->call(operation: 'stop', body: [])
					: static fn () => $client->readCase(reference: 'Z-2');
				$observed[$class]['unlinked'][$rowName] = $this->outcome(operation: $run);
			}
		}

		$rows   = $this->rowShapes()['two linked'];
		$answer = $this->answerShapes()['ok response'];
		$foreignBody = static fn (string $key): array => ['connection' => $key, 'key' => $key, 'meeting' => 'm1'];

		$streaming = new StreamingClient(objectService: $this->objects(rows: $rows), container: $this->container(answer: $answer), answers: new SigningAnswer());
		$observed[StreamingClient::class]['foreignKeyInBody'] = $this->outcome(
			operation: static fn () => $streaming->call(operation: 'start', body: $foreignBody(CaseSystemClient::CONNECTION_KEY))
		);

		$case     = new CaseSystemClient(objectService: $this->objects(rows: $rows), container: $this->container(answer: $answer), answers: new SigningAnswer());
		$caseCall = new \ReflectionMethod($case, 'call');
		$observed[CaseSystemClient::class]['foreignKeyInBody'] = $this->outcome(
			operation: static fn () => $caseCall->invoke($case, 'read-case', $foreignBody(StreamingClient::CONNECTION_KEY))
		);

		return json_decode((string) json_encode($observed), true);
	}//end observe()

	/**
	 * Both clients behave as recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 */
	public function testTheClientsBehaveAsRecorded(): void {
		$observed = $this->observe();
		if (getenv('RECORD_INTEGRIQ_CLIENT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertSame($golden, $observed);
	}//end testTheClientsBehaveAsRecorded()
}//end class
