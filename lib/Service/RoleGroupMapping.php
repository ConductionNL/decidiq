<?php

/**
 * Role to group mapping for the register's authorization rules.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The register's read and write rules name decidiq's own groups
 * (decidiq-administrators, decidiq-secretariat, decidiq-publication-flow).
 * OpenRegister evaluates those rules with literal group names, so an
 * administrator who wants the Griffie group to edit minutes cannot say so
 * without editing the register JSON.
 *
 * This class holds the mapping from each decidiq role to extra Nextcloud
 * groups, and rewrites the authorization blocks of the merged register
 * before it is imported: wherever a rule names a role's group, the mapped
 * groups are added next to it. The role's own group keeps working, so a
 * mapping can only widen who holds a role, never lock the administrators out.
 *
 * Both levels are rewritten: OpenRegister takes the register-level block as
 * the baseline of every schema that has no rules of its own, so a mapping
 * applied to the schema blocks alone would leave those schemas on the old
 * groups.
 *
 * `authenticated` and `public` are not roles and cannot be mapped.
 */
class RoleGroupMapping {

	/**
	 * App config key holding the mapping as JSON: {role: [group, ...]}.
	 */
	public const CONFIG_KEY = 'role_group_mapping';

	/**
	 * The roles the register's rules name, with the groups that stand for
	 * each (decidesk-administrators is the pre-rename name, kept in the rules).
	 */
	public const ROLES = [
		'administrators'   => ['decidiq-administrators', 'decidesk-administrators'],
		'secretariat'      => ['decidiq-secretariat'],
		'publication-flow' => ['decidiq-publication-flow'],
	];

	/**
	 * Keep only known roles and non-empty, distinct group names.
	 *
	 * @param array<mixed> $mapping The mapping as stored or posted.
	 *
	 * @return array<string, list<string>> The clean mapping, every role present.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function normalise(array $mapping): array {
		$clean = [];
		foreach (array_keys(self::ROLES) as $role) {
			$groups = $mapping[$role] ?? [];
			if (is_array($groups) === false) {
				$groups = [];
			}

			$names = [];
			foreach ($groups as $group) {
				if (is_string($group) === true && trim($group) !== '') {
					$names[] = trim($group);
				}
			}

			$clean[$role] = array_values(array_unique($names));
		}

		return $clean;
	}//end normalise()

	/**
	 * Decode the stored mapping.
	 *
	 * @param string $json The app config value.
	 *
	 * @return array<string, list<string>> The mapping, every role present.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function decode(string $json): array {
		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return $this->normalise(mapping: $decoded);
	}//end decode()

	/**
	 * A short signature of the mapping, so a changed mapping re-imports the
	 * register (the import skips a version it has already seen).
	 *
	 * @param array<string, list<string>> $mapping The normalised mapping.
	 *
	 * @return string Empty when nothing is mapped.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function signature(array $mapping): string {
		$mapped = array_filter($mapping);
		if ($mapped === []) {
			return '';
		}

		ksort($mapped);
		return substr(md5((string) json_encode($mapped)), 0, 8);
	}//end signature()

	/**
	 * Add the mapped groups to every rule of the register and its schemas.
	 *
	 * @param array<string,mixed>         $config  The merged register configuration.
	 * @param array<string, list<string>> $mapping The normalised mapping.
	 *
	 * @return array<string,mixed> The configuration with rewritten rules.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function rewrite(array $config, array $mapping): array {
		$extra = $this->extraGroupsByName(mapping: $mapping);
		if ($extra === []) {
			return $config;
		}

		foreach (['registers', 'schemas'] as $section) {
			$items = ($config['components'][$section] ?? []);
			if (is_array($items) === false) {
				continue;
			}

			foreach ($items as $key => $item) {
				if (is_array($item) === false || is_array($item['authorization'] ?? null) === false) {
					continue;
				}

				$config['components'][$section][$key]['authorization'] = $this->rewriteBlock(
					block: $item['authorization'],
					extra: $extra
				);
			}
		}

		return $config;
	}//end rewrite()

	/**
	 * Per record type, who may read, create, change and delete it: the
	 * schema's own rules, or the register's baseline when it has none (the
	 * way OpenRegister applies them).
	 *
	 * @param array<string,mixed> $config The merged, rewritten configuration.
	 *
	 * @return list<array{slug: string, title: string, inherited: bool, rules: array<string, list<array{group: string, conditional: bool}>>}>
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function overview(array $config): array {
		$registers = ($config['components']['registers'] ?? []);
		$baseline  = [];
		if (is_array($registers) === true && $registers !== []) {
			$first    = reset($registers);
			$baseline = (is_array($first) === true && is_array($first['authorization'] ?? null) === true) ? $first['authorization'] : [];
		}

		$schemas = ($config['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return [];
		}

		$rows = [];
		foreach ($schemas as $key => $schema) {
			if (is_array($schema) === false) {
				continue;
			}

			$own    = (is_array($schema['authorization'] ?? null) === true);
			$block  = $baseline;
			if ($own === true) {
				$block = $schema['authorization'];
			}

			$rules = [];
			foreach (['read', 'create', 'update', 'delete'] as $action) {
				$rules[$action] = $this->describe(rules: ($block[$action] ?? []));
			}

			$rows[] = [
				'slug'      => (string) ($schema['slug'] ?? $key),
				'title'     => (string) ($schema['title'] ?? $key),
				'inherited' => ($own === false),
				'rules'     => $rules,
			];
		}//end foreach

		usort($rows, static fn (array $left, array $right): int => strcmp($left['title'], $right['title']));
		return $rows;
	}//end overview()

	/**
	 * The groups of one action's rules, each marked when it holds a condition.
	 *
	 * @param mixed $rules The action's rules.
	 *
	 * @return list<array{group: string, conditional: bool}> One entry per rule.
	 */
	private function describe(mixed $rules): array {
		if (is_array($rules) === false) {
			return [];
		}

		$out = [];
		foreach ($rules as $rule) {
			$group = $this->groupOf(rule: $rule);
			if ($group === '') {
				continue;
			}

			$out[] = ['group' => $group, 'conditional' => is_array($rule)];
		}

		return $out;
	}//end describe()

	/**
	 * Group name to the groups mapped onto its role.
	 *
	 * @param array<string, list<string>> $mapping The normalised mapping.
	 *
	 * @return array<string, list<string>> Keyed by the role's group names.
	 */
	private function extraGroupsByName(array $mapping): array {
		$extra = [];
		foreach (self::ROLES as $role => $names) {
			$groups = ($mapping[$role] ?? []);
			if ($groups === []) {
				continue;
			}

			foreach ($names as $name) {
				$extra[$name] = $groups;
			}
		}

		return $extra;
	}//end extraGroupsByName()

	/**
	 * Rewrite one authorization block: action => list of rules.
	 *
	 * @param array<mixed>                $block The block.
	 * @param array<string, list<string>> $extra Group name to added groups.
	 *
	 * @return array<mixed> The rewritten block.
	 */
	private function rewriteBlock(array $block, array $extra): array {
		foreach ($block as $action => $rules) {
			if (is_array($rules) === false) {
				continue;
			}

			$out = [];
			foreach ($rules as $rule) {
				$out[] = $rule;
				$name  = $this->groupOf(rule: $rule);
				foreach (($extra[$name] ?? []) as $group) {
					$out[] = $this->withGroup(rule: $rule, group: $group);
				}
			}

			$block[$action] = $this->distinct(rules: $out);
		}

		return $block;
	}//end rewriteBlock()

	/**
	 * The group a rule names: the rule itself, or its `group` key.
	 *
	 * @param mixed $rule A string or a conditional rule.
	 *
	 * @return string The group name, or '' when there is none.
	 */
	private function groupOf(mixed $rule): string {
		if (is_string($rule) === true) {
			return $rule;
		}

		if (is_array($rule) === true && is_string($rule['group'] ?? null) === true) {
			return $rule['group'];
		}

		return '';
	}//end groupOf()

	/**
	 * The same rule for another group; a condition stays as it is.
	 *
	 * @param mixed  $rule  A string or a conditional rule.
	 * @param string $group The group to name.
	 *
	 * @return mixed The rule for that group.
	 */
	private function withGroup(mixed $rule, string $group): mixed {
		if (is_array($rule) === true) {
			$rule['group'] = $group;
			return $rule;
		}

		return $group;
	}//end withGroup()

	/**
	 * Drop repeated rules, keeping the first of each.
	 *
	 * @param list<mixed> $rules The rules.
	 *
	 * @return list<mixed> Distinct rules in order.
	 */
	private function distinct(array $rules): array {
		$seen = [];
		$out  = [];
		foreach ($rules as $rule) {
			$key = (string) json_encode($rule);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$out[]      = $rule;
		}

		return $out;
	}//end distinct()
}//end class
