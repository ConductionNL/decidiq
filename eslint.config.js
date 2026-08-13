const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		extends: compat.extends('@nextcloud'),

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
						[
							'@floating-ui/dom-actual',
							'./node_modules/@floating-ui/dom',
						],
						['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
					],
					extensions: ['.js', '.ts', '.vue', '.json', '.css'],
				},
			},
		},

		rules: {
			// Allow unused i18n functions (t, n) — imported for future translation wiring
			'no-unused-vars': [
				'error',
				{ varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' },
			],
			'jsdoc/require-jsdoc': 'off',
			// Allow @spec tag used for OpenSpec traceability links
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
			'vue/first-attribute-linebreak': 'off',
			// Vue 3 migration (ADR-066): the shared @nextcloud eslint preset is still
			// Vue-2-oriented and enables `vue/no-v-for-template-key`, which forbids a
			// `:key` on a `<template v-for>`. Under Vue 3 the SFC compiler REQUIRES the
			// key to sit on the `<template>` (a key on a child throws "VueCompilerError:
			// <template v-for> key should be placed on the <template> tag"). The Vue-2
			// rule is therefore incorrect for this app and is disabled.
			'vue/no-v-for-template-key': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/namespace': 'off', // disable namespace checking to avoid parser requirement
			'import/default': 'off', // disable default import checking to avoid parser requirement
			'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
			'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
		},
	},
	// eslint-config-prettier LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything placed after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule; prettier has no opinion about any of them.
	// `indent` is now off HERE and enforced by prettier's `useTabs: true`
	// instead — the same tab, from the tool that also covers CSS and SCSS,
	// which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
