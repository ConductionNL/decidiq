<?php

/**
 * FilinqStamp Unit Tests
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Support\FilinqStamp;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * filinq absent answers null; filinq refusing a paper is a 422 refusal.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\Support\FilinqStamp
 *
 * @uses \OCA\Decidiq\Support\FleetAppId
 * @uses \OCA\Decidiq\Exception\PaperStampRefusedException
 */
class FilinqStampTest extends TestCase {

	/**
	 * A container that knows filinq's stamping service, or nothing.
	 *
	 * @param object|null $service The service, or null for none.
	 *
	 * @return ContainerInterface
	 */
	private function container(?object $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($service !== null && $id === 'OCA\Filinq\Service\PdfStampService') {
					return $service;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * The stamp is asked of filinq with the text, on both placements.
	 *
	 * @return void
	 */
	public function testItStampsThroughFilinq(): void {
		$service = new class {
			/** @var array<int, mixed> */
			public array $calls = [];

			/**
			 * @param string               $pdf     The PDF.
			 * @param string               $text    The text.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return string
			 */
			public function stamp(string $pdf, string $text, array $options=[]): string {
				$this->calls[] = [$pdf, $text, $options];
				return 'stamped:' . $pdf;
			}
		};

		$stamp = new FilinqStamp($this->container($service), $this->createMock(LoggerInterface::class));

		self::assertSame('stamped:%PDF', $stamp->stamp('%PDF', 'Aisha Bakker, 2026-10-03 20:15'));
		self::assertSame([['%PDF', 'Aisha Bakker, 2026-10-03 20:15', ['placement' => 'both']]], $service->calls);
	}//end testItStampsThroughFilinq()

	/**
	 * Without filinq there is no stamp, and that is answered as null.
	 *
	 * @return void
	 */
	public function testWithoutFilinqItAnswersNull(): void {
		$stamp = new FilinqStamp($this->container(null), $this->createMock(LoggerInterface::class));

		self::assertNull($stamp->stamp('%PDF', 'x'));
	}//end testWithoutFilinqItAnswersNull()

	/**
	 * A filinq without the stamping method counts as absent.
	 *
	 * @return void
	 */
	public function testAFilinqWithoutTheMethodAnswersNull(): void {
		$stamp = new FilinqStamp($this->container(new \stdClass()), $this->createMock(LoggerInterface::class));

		self::assertNull($stamp->stamp('%PDF', 'x'));
	}//end testAFilinqWithoutTheMethodAnswersNull()

	/**
	 * A paper filinq refuses is a 422 refusal, never the input back.
	 *
	 * @return void
	 */
	public function testARefusedPaperIsA422(): void {
		$service = new class {
			/**
			 * @param string               $pdf     The PDF.
			 * @param string               $text    The text.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return string
			 */
			public function stamp(string $pdf, string $text, array $options=[]): string {
				throw new RuntimeException('encrypted');
			}
		};

		$stamp = new FilinqStamp($this->container($service), $this->createMock(LoggerInterface::class));

		try {
			$stamp->stamp('%PDF', 'x');
			self::fail('A refused stamp must throw.');
		} catch (PaperStampRefusedException $e) {
			self::assertSame(422, $e->getStatus());
			self::assertSame(PaperStampRefusedException::UNSTAMPABLE, $e->getMessage());
		}
	}//end testARefusedPaperIsA422()

	/**
	 * An empty answer from filinq is a refusal too.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsARefusal(): void {
		$service = new class {
			/**
			 * @param string               $pdf     The PDF.
			 * @param string               $text    The text.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return string
			 */
			public function stamp(string $pdf, string $text, array $options=[]): string {
				return '';
			}
		};

		$stamp = new FilinqStamp($this->container($service), $this->createMock(LoggerInterface::class));

		$this->expectException(PaperStampRefusedException::class);
		$stamp->stamp('%PDF', 'x');
	}//end testAnEmptyAnswerIsARefusal()
}//end class
