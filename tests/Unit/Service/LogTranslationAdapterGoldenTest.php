<?php

/**
 * Golden runs of LogTranslationAdapter::translate().
 *
 * Pins the answer and the log lines for equal locales, no delegate, and every
 * shape a delegate can answer in: an array with text (with and without its
 * own success and message), an array without text, a string, an empty
 * string, no translate method, and a throw. Pinned before translate() was
 * split into named steps.
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
 * @spec exclude golden test of the translation adapter, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\LogTranslationAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LogTranslationAdapterGoldenTest extends TestCase {

	/**
	 * Every log line, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * Every answer and log line matches.
	 *
	 * @return void
	 */
	public function testEveryAnswerIsUnchanged(): void {
		$answers = [
			'array with all' => ['text' => 'Hallo', 'success' => false, 'message' => 'partial'],
			'array with text' => ['text' => 42],
			'array without text' => ['message' => 'nothing'],
			'string' => 'Hallo',
			'empty string' => '',
			'throws' => 'throw',
		];
		$runs = [];
		foreach ($answers as $name => $answer) {
			$this->logs = [];
			$delegate = new class($answer) {
				/**
				 * Make a delegate.
				 *
				 * @param mixed $answer What to answer.
				 */
				public function __construct(private readonly mixed $answer) {
				}

				/**
				 * Answer a translation.
				 *
				 * @param string $text   The text.
				 * @param string $source The source locale.
				 * @param string $target The target locale.
				 *
				 * @return mixed The answer.
				 */
				public function translate(string $text, string $source, string $target): mixed {
					if ($this->answer === 'throw') {
						throw new RuntimeException('delegate down');
					}

					return $this->answer;
				}
			};
			$runs[$name] = [$this->adapter($delegate)->translate('Hello', 'en', 'nl'), $this->logs];
		}

		$this->logs = [];
		$runs['no translate method'] = [$this->adapter(new \stdClass())->translate('Hello', 'en', 'nl'), $this->logs];
		$this->logs = [];
		$runs['no delegate'] = [$this->adapter(null)->translate('Hello', 'en', 'nl'), $this->logs];
		$this->logs = [];
		$runs['equal locales'] = [$this->adapter(null)->translate('Hello', 'nl', 'nl'), $this->logs];

		$dormant = ['success' => true, 'text' => 'Hello', 'provider' => 'log', 'message' => 'No translation provider configured; original text returned.'];
		$dormantLog = ['info', 'Decidiq: LogTranslationAdapter — translation requested (dormant default)', ['sourceLocale' => 'en', 'targetLocale' => 'nl', 'length' => 5]];
		self::assertSame(
			[
				'array with all' => [['success' => false, 'text' => 'Hallo', 'provider' => 'openconnector', 'message' => 'partial'], []],
				'array with text' => [['success' => true, 'text' => '42', 'provider' => 'openconnector', 'message' => 'Translated via openconnector.'], []],
				'array without text' => [$dormant, [$dormantLog]],
				'string' => [['success' => true, 'text' => 'Hallo', 'provider' => 'openconnector', 'message' => 'Translated via openconnector.'], []],
				'empty string' => [$dormant, [$dormantLog]],
				'throws' => [$dormant, [['warning', 'Decidiq: openconnector translation delegate failed; falling back to log adapter', ['exception' => 'delegate down']], $dormantLog]],
				'no translate method' => [$dormant, [$dormantLog]],
				'no delegate' => [$dormant, [$dormantLog]],
				'equal locales' => [['success' => true, 'text' => 'Hello', 'provider' => 'noop', 'message' => 'Source and target locales are equal.'], []],
			],
			$runs
		);
	}//end testEveryAnswerIsUnchanged()

	/**
	 * The adapter over a container that serves the given delegate, or nothing.
	 *
	 * @param object|null $delegate The delegate.
	 *
	 * @return LogTranslationAdapter The adapter.
	 */
	private function adapter(?object $delegate): LogTranslationAdapter {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($delegate): object {
				if ($delegate === null) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $delegate;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new LogTranslationAdapter($container, $logger);
	}//end adapter()
}//end class
