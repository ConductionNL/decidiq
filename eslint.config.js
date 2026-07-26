const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' }],
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
}])
