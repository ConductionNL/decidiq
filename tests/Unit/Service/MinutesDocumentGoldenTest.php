<?php

/**
 * Golden test for MinutesDocumentService::generate.
 *
 * Runs generate over formats (markdown, pdf, unsupported), minutes with and
 * without content (draft fallback), live item notes in every shape (not a
 * list, empty, non-array entries, blank notes and decisions, no agenda item,
 * an agenda item found with a title, a name or neither, missing, or a lookup
 * that throws), a PDF that renders or does not, folder writes that fail for
 * the PDF or for both files, earlier generated documents (a list or junk) and
 * a bookkeeping save that throws. It records every object-service call by
 * parameter name, every file write, every HTML handed to the PDF renderer,
 * every log line and what generate returns or throws. Dates are masked. The
 * expected values live in tests/fixtures/minutes-document-golden.json; run
 * with RECORD_MINUTES_DOCUMENT_GOLDEN=1 to write them, then run again without it.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\MinutesDocumentService;
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
 * Golden test for the minutes document.
 *
 * @covers \OCA\Decidiq\Service\MinutesDocumentService
 * @uses   \OCA\Decidiq\Service\MinutesRecordReader
 * @uses   \OCA\Decidiq\Exception\MissingObjectException
 * @uses   \OCA\Decidiq\Exception\MissingRelationException
 */
class MinutesDocumentGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-document-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The case being run.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * Every document generates as recorded.
	 *
	 * @return void
	 */
	public function testEveryDocumentMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log  = [];
			$this->case = $case;
			try {
				$returns = ['returns' => $this->service()->generate('min-1', ($case['format'] ?? 'markdown'), 'Jan')];
			} catch (Throwable $e) {
				$returns = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$json          = (string)json_encode($returns + ['calls' => $this->log], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$actual[$name] = json_decode((string)preg_replace('/\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}| \d{4})/', '<date>', $json), true);
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MINUTES_DOCUMENT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryDocumentMatchesTheRecording()

	/**
	 * The cases: the minutes and how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$base     = ['title' => 'Raad <b>&</b>', 'version' => 3, 'meeting' => 'm-1'];
		$markdown = "# Titel\n## Sub\n### Klein\n1. Eerste **vet** en _schuin_\n---\n\nGewone regel <script>\n  ---  ";
		$notes    = [
			'geen lijst',
			['notes' => '  ', 'decisions' => ''],
			['agendaItem' => '', 'notes' => 'Zonder punt'],
			['agendaItem' => 'ai-title', 'notes' => 'Met titel', 'decisions' => 'Aangenomen'],
			['agendaItem' => 'ai-name', 'decisions' => 'Alleen besluit'],
			['agendaItem' => 'ai-none', 'notes' => 'Geen naam'],
			['agendaItem' => 'ai-missing', 'notes' => 'Weg'],
			['agendaItem' => 'ai-throws', 'notes' => 'Fout'],
		];

		return [
			'unsupported-format' => ['format' => 'docx', 'minutes' => $base + ['content' => 'x']],
			'no-meeting'         => ['minutes' => ['title' => 'Raad', 'content' => 'x']],
			'markdown-content'   => ['minutes' => $base + ['content' => "  Inhoud\n  "]],
			'draft-fallback'     => ['minutes' => $base + ['content' => '   ']],
			'no-title-version'   => ['minutes' => ['meeting' => 'm-1', 'content' => 'x']],
			'item-notes'         => ['minutes' => $base + ['content' => 'Inhoud', 'itemNotes' => $notes]],
			'item-notes-junk'    => ['minutes' => $base + ['content' => 'Inhoud', 'itemNotes' => 'tekst']],
			'item-notes-empty'   => ['minutes' => $base + ['content' => 'Inhoud', 'itemNotes' => []]],
			'pdf-rendered'       => ['format' => 'pdf', 'pdf' => '%PDF-bytes', 'minutes' => $base + ['content' => $markdown]],
			'pdf-unavailable'    => ['format' => 'pdf', 'minutes' => $base + ['content' => $markdown]],
			'pdf-write-fails'    => ['format' => 'pdf', 'pdf' => '%PDF-bytes', 'write' => ['pdf' => null], 'minutes' => $base + ['content' => 'x']],
			'all-writes-fail'    => ['format' => 'pdf', 'pdf' => '%PDF-bytes', 'write' => ['pdf' => null, 'md' => null], 'minutes' => $base + ['content' => 'x']],
			'markdown-write-fails' => ['write' => ['md' => null], 'minutes' => $base + ['content' => 'x']],
			'earlier-documents'  => ['minutes' => $base + ['content' => 'x', 'generatedDocuments' => [['path' => '/old.md']]]],
			'documents-junk'     => ['minutes' => $base + ['content' => 'x', 'generatedDocuments' => 'oud']],
			'save-throws'        => ['save' => 'throw', 'minutes' => $base + ['content' => 'x']],
		];
	}//end cases()

	/**
	 * The service over the current case.
	 *
	 * @return MinutesDocumentService
	 */
	private function service(): MinutesDocumentService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		foreach (['setRegister', 'setSchema', 'find', 'saveObject'] as $method) {
			$objects->method($method)->willReturnCallback(fn (...$a) => $this->call(method: $method, args: $a, objects: $objects));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$generation = $this->createMock(MinutesGenerationService::class);
		$generation->method('generateDraft')->willReturnCallback(
			function (string $id): string {
				$this->log[] = ['generateDraft' => $id];
				return "# Concept\nAgenda";
			}
		);

		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturnCallback(
			function (string $html, string $title, string $context): ?string {
				$this->log[] = ['fromHtml' => [$html, $title, $context]];
				return ($this->case['pdf'] ?? null);
			}
		);

		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('writeMeetingFile')->willReturnCallback(
			function (array $meeting, string $subfolder, string $fileName, string $content): ?string {
				$this->log[] = ['writeMeetingFile' => [$meeting['id'] ?? null, $subfolder, $fileName, $content]];
				$extension   = substr($fileName, (strrpos($fileName, '.') + 1));
				if (array_key_exists($extension, ($this->case['write'] ?? [])) === true) {
					return $this->case['write'][$extension];
				}

				return '/Meetings/m-1/Minutes/' . $fileName;
			}
		);

		return new MinutesDocumentService(
			filinqPdf: $pdf,
			logger: $logger,
			generationService: $generation,
			folderService: $folders,
			objectService: $objects,
		);
	}//end service()

	/**
	 * Record an object-service call and answer it.
	 *
	 * @param string                 $method  The method called
	 * @param array<mixed>           $args    The positional arguments
	 * @param ObjectServiceInterface $objects The double, answered by the setters
	 *
	 * @return mixed The answer.
	 */
	private function call(string $method, array $args, ObjectServiceInterface $objects): mixed {
		$named       = $this->named(class: ObjectServiceInterface::class, method: $method, args: $args);
		$this->log[] = [$method => $named];
		if ($method === 'saveObject') {
			if (($this->case['save'] ?? null) === 'throw') {
				throw new RuntimeException('save down');
			}

			return $this->entity(data: []);
		}

		if ($method !== 'find') {
			return $objects;
		}

		return $this->find(id: (string)($named['id'] ?? ''));
	}//end call()

	/**
	 * The entity find() answers for an id.
	 *
	 * @param string $id The id asked for.
	 *
	 * @return ObjectEntity|null The entity.
	 */
	private function find(string $id): ?ObjectEntity {
		$rows = [
			'min-1'      => $this->case['minutes'],
			'm-1'        => ['id' => 'm-1', 'title' => 'Vergadering'],
			'ai-title'   => ['title' => 'Begroting', 'name' => 'niet deze'],
			'ai-name'    => ['name' => 'Naam'],
			'ai-none'    => ['other' => 1],
			'ai-missing' => null,
		];
		if ($id === 'ai-throws') {
			throw new RuntimeException('item down');
		}

		if (($rows[$id] ?? null) === null) {
			return null;
		}

		return $this->entity(data: $rows[$id]);
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
