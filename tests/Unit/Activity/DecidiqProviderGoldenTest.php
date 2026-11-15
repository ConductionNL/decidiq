<?php

/**
 * Golden runs of DecidiqProvider::parse().
 *
 * Records every setter the provider calls on the event, with its arguments,
 * for each known subject with and without a deep-link target, and the
 * translation calls it makes. Pinned before parse() was split into named
 * steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude golden test of the activity rendering, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Activity;

use OCA\Decidiq\Activity\DecidiqProvider;
use OCA\Decidiq\Activity\GovernanceSetting;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class DecidiqProviderGoldenTest extends TestCase {

	/**
	 * Everything the provider did, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Every subject renders the same plain and rich subject, parameters, link and icon.
	 *
	 * @return void
	 */
	public function testEverySubjectRendersTheSame(): void {
		$subjects = [
			DecidiqProvider::SUBJECT_DECISION_RECORDED,
			DecidiqProvider::SUBJECT_DECISION_PUBLISHED,
			DecidiqProvider::SUBJECT_MEETING_TRANSITION,
			DecidiqProvider::SUBJECT_VOTE_INITIATED,
			DecidiqProvider::SUBJECT_RESOLUTION_ADOPTED,
		];
		$runs = [];
		foreach ($subjects as $subject) {
			foreach (['with link' => ['segment' => 'decisions', 'uuid' => 'u-1'], 'without link' => ['uuid' => 'u-2'], 'bare' => []] as $name => $target) {
				$this->trace = [];
				$this->provider()->parse('nl', $this->event($subject, (['title' => 'Begroting', 'status' => 'opened'] + $target)));
				$runs[$subject . ' ' . $name] = $this->trace;
			}
		}

		self::assertSame(
			[
				['factory', 'decidiq', 'nl'],
				['t', 'Decision "%1$s" was recorded', ['Begroting']],
				['t', 'Decision {object} was recorded', []],
				['url', '/apps/decidiq/#/decisions/u-1'],
				['setParsedSubject', 'Decision "Begroting" was recorded'],
				['setRichSubject', 'Decision {object} was recorded', ['object' => ['type' => 'highlight', 'id' => 'u-1', 'name' => 'Begroting', 'link' => 'https://cloud.example/apps/decidiq/#/decisions/u-1']]],
				['setLink', 'https://cloud.example/apps/decidiq/#/decisions/u-1'],
				['imagePath', 'decidiq', 'app-dark.svg'],
				['url', '/img/app-dark.svg'],
				['setIcon', 'https://cloud.example/img/app-dark.svg'],
			],
			$runs[DecidiqProvider::SUBJECT_DECISION_RECORDED . ' with link']
		);
		self::assertSame(
			['t', 'Meeting {object} moved to "%1$s"', ['opened']],
			$runs[DecidiqProvider::SUBJECT_MEETING_TRANSITION . ' bare'][2]
		);
		$fingerprint = [];
		foreach ($runs as $name => $trace) {
			$fingerprint[$name] = md5((string)json_encode($trace));
		}

		self::assertSame(
			[
				DecidiqProvider::SUBJECT_DECISION_RECORDED . ' without link' => [['factory', 'decidiq', 'nl'], ['t', 'Decision "%1$s" was recorded', ['Begroting']], ['t', 'Decision {object} was recorded', []], ['setParsedSubject', 'Decision "Begroting" was recorded'], ['setRichSubject', 'Decision {object} was recorded', ['object' => ['type' => 'highlight', 'id' => 'u-2', 'name' => 'Begroting', 'link' => '']]], ['imagePath', 'decidiq', 'app-dark.svg'], ['url', '/img/app-dark.svg'], ['setIcon', 'https://cloud.example/img/app-dark.svg']],
			],
			[DecidiqProvider::SUBJECT_DECISION_RECORDED . ' without link' => $runs[DecidiqProvider::SUBJECT_DECISION_RECORDED . ' without link']]
		);
		$golden = __DIR__ . '/../../fixtures/activity-provider-golden.json';
		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			file_put_contents($golden, json_encode($runs, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents($golden), true), json_decode((string)json_encode($runs), true));
		self::assertCount(15, $fingerprint);
	}//end testEverySubjectRendersTheSame()

	/**
	 * The provider over recording doubles.
	 *
	 * @return DecidiqProvider The provider.
	 */
	private function provider(): DecidiqProvider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			function (string $text, array $params = []): string {
				$this->trace[] = ['t', $text, $params];
				return vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $params);
			}
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $language = null) use ($l10n): IL10N {
				$this->trace[] = ['factory', $app, $language];
				return $l10n;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(
			function (string $app, string $image): string {
				$this->trace[] = ['imagePath', $app, $image];
				return '/img/' . $image;
			}
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			function (string $path): string {
				$this->trace[] = ['url', $path];
				return 'https://cloud.example' . $path;
			}
		);
		return new DecidiqProvider(languageFactory: $factory, urlGenerator: $urls);
	}//end provider()

	/**
	 * A recording governance event.
	 *
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return IEvent The event.
	 */
	private function event(string $subject, array $params): IEvent {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('decidiq');
		$event->method('getType')->willReturn(GovernanceSetting::TYPE_GOVERNANCE);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		foreach (['setParsedSubject', 'setRichSubject', 'setLink', 'setIcon'] as $setter) {
			$event->method($setter)->willReturnCallback(
				function (...$args) use ($setter, $event): IEvent {
					$this->trace[] = [$setter, ...$args];
					return $event;
				}
			);
		}

		return $event;
	}//end event()
}//end class
