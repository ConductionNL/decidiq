<?php

/**
 * Decidiq CaseSystemWorld
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use Opis\JsonSchema\Validator;

/**
 * An in-memory register and a case system behind integriq's call service,
 * for the case-system exchange tests. Every object saved into the decidiq
 * register is validated against the merged register schema (base register
 * plus every register.d fragment) with Opis, so a payload the real schema
 * refuses fails the test that wrote it.
 */
final class CaseSystemWorld {

	/**
	 * Objects by id: [register, schema, data].
	 *
	 * @var array<string, array{register:string, schema:string, data:array<string,mixed>}>
	 */
	public array $objects = [];

	/**
	 * Validation errors of saved payloads.
	 *
	 * @var list<string>
	 */
	public array $invalid = [];

	/**
	 * Calls made to the case system: [operation, body].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	public array $calls = [];

	/**
	 * Answers per operation: an array, or a callable taking the body.
	 *
	 * @var array<string, mixed>
	 */
	public array $answers = [];

	/**
	 * Counter for new ids.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Put an object.
	 *
	 * @param string              $schema   The schema slug.
	 * @param array<string,mixed> $data     The data.
	 * @param string|null         $id       The id.
	 * @param string              $register The register.
	 *
	 * @return string The id.
	 */
	public function put(string $schema, array $data, ?string $id=null, string $register='decidiq'): string {
		$this->sequence++;
		$id = ($id ?? sprintf('00000000-0000-4000-8000-%012d', $this->sequence));
		$this->objects[$id] = ['register' => $register, 'schema' => $schema, 'data' => $data];
		return $id;
	}//end put()

	/**
	 * Objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string,mixed>> Data by id.
	 */
	public function all(string $schema): array {
		$result = [];
		foreach ($this->objects as $id => $object) {
			if ($object['schema'] === $schema) {
				$result[$id] = $object['data'];
			}
		}

		return $result;
	}//end all()

	/**
	 * Answer one findAll.
	 *
	 * @param array<string,mixed> $config The findAll config.
	 *
	 * @return list<string> Matching ids.
	 */
	public function match(array $config): array {
		$filters = (array)($config['filters'] ?? []);
		$ids     = [];
		foreach ($this->objects as $id => $object) {
			if (($filters['register'] ?? $object['register']) !== $object['register'] || ($filters['schema'] ?? $object['schema']) !== $object['schema']) {
				continue;
			}

			$keep = true;
			foreach ($filters as $key => $value) {
				if (in_array($key, ['register', 'schema'], true) === true) {
					continue;
				}

				$field = str_replace('_relations.', '', (string)$key);
				if ((string)($object['data'][$field] ?? '') !== (string)$value) {
					$keep = false;
				}
			}

			if ($keep === true) {
				$ids[] = (string)$id;
			}
		}//end foreach

		return $ids;
	}//end match()

	/**
	 * Save an object, validating it against the merged schema first.
	 *
	 * @param array<string,mixed> $data     The data.
	 * @param string              $register The register.
	 * @param string              $schema   The schema slug.
	 * @param string|null         $id       The id, null for new.
	 *
	 * @return string The id.
	 */
	public function save(array $data, string $register, string $schema, ?string $id): string {
		if ($register === 'decidiq') {
			$error = $this->validate(data: $data, slug: $schema);
			if ($error !== null) {
				$this->invalid[] = $schema . ': ' . $error;
			}
		}

		return $this->put(schema: $schema, data: $data, id: $id, register: $register);
	}//end save()

	/**
	 * Answer one case-system call.
	 *
	 * @param string              $operation The operation.
	 * @param array<string,mixed> $body      The body.
	 *
	 * @return array<string,mixed>
	 */
	public function answer(string $operation, array $body): array {
		$this->calls[] = [$operation, $body];
		$answer        = ($this->answers[$operation] ?? []);
		if (is_callable($answer) === true) {
			return (array)$answer($body);
		}

		return (array)$answer;
	}//end answer()

	/**
	 * The calls of one operation.
	 *
	 * @param string $operation The operation.
	 *
	 * @return list<array<string,mixed>> Their bodies.
	 */
	public function callsOf(string $operation): array {
		return array_values(array_map(static fn (array $call): array => $call[1], array_filter($this->calls, static fn (array $call): bool => $call[0] === $operation)));
	}//end callsOf()

	/**
	 * Validate data against the merged register schema with this slug.
	 *
	 * @param array<string,mixed> $data The data.
	 * @param string              $slug The schema slug.
	 *
	 * @return string|null The first error, or null when valid.
	 */
	public function validate(array $data, string $slug): ?string {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$schema   = [];
		foreach (array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: [])) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		if ($schema === []) {
			return 'no schema ' . $slug;
		}

		$strip  = static function (mixed $node) use (&$strip): mixed {
			if (is_array($node) === false) {
				return $node;
			}

			unset($node['$ref'], $node['facetable'], $node['x-openregister'], $node['inversedBy'], $node['nullable']);
			foreach ($node as $key => $value) {
				if (is_array($value) === true && in_array($key, ['required', 'enum'], true) === false) {
					$node[$key] = $strip($value);
				}
			}

			if (($node['type'] ?? null) === 'object' && isset($node['properties']) === true) {
				$node['additionalProperties'] = false;
			}

			return $node;
		};
		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $strip($schema['properties']), 'additionalProperties' => false]))
		);
		if ($result->isValid() === true) {
			return null;
		}

		return (string)json_encode($result->error()?->keyword()) . ' at ' . implode('/', $result->error()?->data()->fullPath() ?? []);
	}//end validate()
}//end class
