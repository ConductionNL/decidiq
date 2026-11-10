<?php
/**
 * Golden test for how the two minutes services load minutes and their meeting.
 *
 * Runs MinutesGenerationService (generateDraft, transition, reject) and
 * MinutesDocumentService (generate) over minutes and meeting shapes, and
 * records, by parameter name and in order, every object-service call they make
 * (so the register/schema context set before each fetch is pinned), every log
 * line, and what each call returns or throws. The expected values live in
 * tests/fixtures/minutes-lookup-golden.json; run with
 * RECORD_MINUTES_LOOKUP_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\MinutesDocumentService;
use OCA\Decidiq\Service\MinutesDraftRenderer;
use OCA\Decidiq\Service\MinutesGenerationService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Golden test for the minutes and meeting lookups of the two minutes services.
 *
 * @covers \OCA\Decidiq\Service\MinutesGenerationService
 * @covers \OCA\Decidiq\Service\MinutesDocumentService
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
 * @uses   \OCA\Decidiq\Service\MinutesRecordReader
 * @uses   \OCA\Decidiq\Exception\MissingObjectException
 * @uses   \OCA\Decidiq\Exception\MissingRelationException
 */
class MinutesLookupGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-lookup-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The recording object service of the current case.
	 *
	 * @var ObjectServiceInterface|null
	 */
	private ?ObjectServiceInterface $objects = null;

	/**
	 * Every case answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryLookupMatchesTheRecording(): void {
		$calls = [
			'draft'           => fn ($gen, $doc, $id) => $gen->generateDraft($id),
			'to review'       => fn ($gen, $doc, $id) => $gen->transition($id, 'review', 'Jan'),
			'to approved'     => fn ($gen, $doc, $id) => $gen->transition($id, 'approved', 'Jan'),
			'reject'          => fn ($gen, $doc, $id) => $gen->reject($id, 'Needs work', 'u1'),
			'reject blank'    => fn ($gen, $doc, $id) => $gen->reject($id, ' ', 'u1'),
			'document'        => fn ($gen, $doc, $id) => $doc->generate($id, 'markdown', 'Jan'),
			'document format' => fn ($gen, $doc, $id) => $doc->generate($id, 'docx', 'Jan'),
		];

		$actual = [];
		foreach (array_keys($this->minutesShapes()) as $id) {
			foreach ($calls as $name => $call) {
				$this->log = [];
				try {
					$result = ['returns' => $call(...[...$this->services(), $id])];
				} catch (Throwable $e) {
					$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
				}

				$actual[$id][$name] = $result + ['calls' => $this->log];
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}| \d{4})/', '<date>', $json);
		if (getenv('RECORD_MINUTES_LOOKUP_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryLookupMatchesTheRecording()

	/**
	 * Minutes shapes by id: the stored object, null for none, or an exception.
	 *
	 * @return array<string, mixed>
	 */
	private function minutesShapes(): array {
		$base = ['title' => 'Raad', 'version' => 2, 'content' => 'Body', 'lifecycle' => 'review'];
		return [
			'gone'          => null,
			'find-throws'   => new RuntimeException('register down'),
			'no-meeting'    => $base,
			'hydrated'      => $base + ['relations' => ['meeting' => ['id' => 'm-1', 'title' => 'Embedded']]],
			'hydrated-noid' => $base + ['meeting' => ['title' => 'No id']],
			'empty-ref'     => $base + ['meeting' => ''],
			'ref'           => $base + ['meeting' => 'm-1'],
			'ref-draft'     => ['title' => 'Raad', 'lifecycle' => 'draft', 'signedBy' => ['Piet'], 'meeting' => 'm-1'],
			'ref-none'      => $base + ['meeting' => 'm-none'],
			'ref-throws'    => $base + ['meeting' => 'm-throws'],
		];
	}//end minutesShapes()

	/**
	 * Build both services over one recording object service.
	 *
	 * @return array{0: MinutesGenerationService, 1: MinutesDocumentService}
	 */
	private function services(): array {
		$objects       = $this->createMock(ObjectServiceInterface::class);
		$this->objects = $objects;
		$objects->method('setRegister')->willReturnCallback(fn (...$a) => $this->call(method: 'setRegister', args: $a));
		$objects->method('setSchema')->willReturnCallback(fn (...$a) => $this->call(method: 'setSchema', args: $a));
		$objects->method('find')->willReturnCallback(fn (...$a) => $this->call(method: 'find', args: $a));
		$objects->method('findAll')->willReturnCallback(fn (...$a) => $this->call(method: 'findAll', args: $a));
		$objects->method('saveObject')->willReturnCallback(fn (...$a) => $this->call(method: 'saveObject', args: $a));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$renderer = $this->createMock(MinutesDraftRenderer::class);
		$renderer->method('render')->willReturnCallback(fn (...$a) => json_encode($this->named(class: MinutesDraftRenderer::class, method: 'render', args: $a)));

		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('writeMeetingFile')->willReturnCallback(
			function (...$a): string {
				$this->log[] = ['writeMeetingFile' => $this->named(class: MeetingFolderService::class, method: 'writeMeetingFile', args: $a)];
				return '/Meetings/m-1/Minutes/doc.md';
			}
		);

		$generation = new MinutesGenerationService(logger: $logger, renderer: $renderer, objectService: $objects);
		$document   = new MinutesDocumentService(
			filinqPdf: $this->createMock(FilinqPdf::class),
			logger: $logger,
			generationService: $generation,
			folderService: $folders,
			objectService: $objects,
		);

		return [$generation, $document];
	}//end services()

	/**
	 * Record an object-service call and answer it.
	 *
	 * @param string       $method The method called
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return mixed The answer.
	 */
	private function call(string $method, array $args): mixed {
		$named       = $this->named(class: ObjectServiceInterface::class, method: $method, args: $args);
		$this->log[] = [$method => $named];
		if ($method === 'find') {
			return $this->find(id: (string)($named['id'] ?? ''));
		}

		if ($method === 'saveObject') {
			return $this->entity(data: ['saved' => $named['object']]);
		}

		if ($method === 'findAll') {
			return [];
		}

		return $this->objects;
	}//end call()

	/**
	 * The entity find() answers for an id.
	 *
	 * @param string $id The id asked for.
	 *
	 * @return ObjectEntity|null The entity.
	 */
	private function find(string $id): ?ObjectEntity {
		$meetings = [
			'm-1'      => ['id' => 'm-1', 'title' => 'Fetched'],
			'm-none'   => null,
			'm-throws' => new RuntimeException('meeting down'),
		];
		$shape    = (array_key_exists($id, $meetings) === true ? $meetings[$id] : ($this->minutesShapes()[$id] ?? null));
		if ($shape instanceof Throwable) {
			throw $shape;
		}

		if ($shape === null) {
			return null;
		}

		return $this->entity(data: $shape);
	}//end find()

	/**
	 * An entity double carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getObject', 'jsonSerialize'])->getMock();
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Name positional mock arguments by the loaded method's parameters, leaving out defaults.
	 *
	 * @param string       $class  The class or interface
	 * @param string       $method The method
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return array<string, mixed> The named arguments.
	 */
	private function namedAsDeclared(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end namedAsDeclared()

	/**
	 * The arguments by name; a find() or findSilent() call is expanded back into
	 * its old parameter names, so the recording describes the read, not the call shape.
	 *
	 * @param mixed $class See namedAsDeclared()
	 * @param mixed $method See namedAsDeclared()
	 * @param mixed $args See namedAsDeclared()
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $args): array {
		$named = $this->namedAsDeclared($class, $method, $args);
		if (in_array($method, ['find', 'findSilent'], true) === true) {
			return FindCallShape::legacyNamed(named: $named);
		}

		return $named;
	}//end named()
}//end class
