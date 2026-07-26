const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'decidesk'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	personalSettings: {
		import: path.join(__dirname, 'src', 'personal.js'),
		filename: appId + '-personal.js',
	},
	// Global integration-leaf bootstrap loaded on EVERY Nextcloud page via
	// Util::addInitScript (ADR-019). Registers the "Besluitvorming" decisions
	// leaf so it surfaces on host objects (e.g. a procest case) without the
	// full decidesk app bundle.
	integrationInit: {
		import: path.join(__dirname, 'src', 'integration-init.js'),
		filename: appId + '-integration-init.js',
	},
}

// Resolve async (lazy) chunk URLs relative to the entry script's own location
// at runtime, instead of the base config's fixed publicPath. CnPageRenderer
// loads page components (e.g. CnDashboardPage) via defineAsyncComponent, so each
// lands in its own chunk. On installs that serve this app from /custom_apps/
// (rather than the virtual /apps/ path the base publicPath assumes) the fixed
// path 404s and the page renders blank. 'auto' derives the base from
// document.currentScript (decidesk-main.js under /custom_apps/decidesk/js/), so
// the chunks load from the same directory the entry did.
webpackConfig.output = { ...(webpackConfig.output || {}), publicPath: 'auto' }

// Use local source when available (monorepo dev), otherwise fall back to npm package.
// CN_NEXTCLOUD_VUE_SRC env override lets a sibling worktree pin a specific
// nextcloud-vue source path (used when iterating on an unmerged nc-vue branch).
const localLib = process.env.CN_NEXTCLOUD_VUE_SRC
	|| path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		// VUE 3 (ADR-066): one ABSOLUTE Vue file so decidesk + the aliased lib
		// source share a single copy (dual-copy = two currentRenderingInstance
		// states → CnAppRoot null crash). vue-loader finds the real SFC
		// compiler via @vue/compiler-sfc.
		//
		// STRADDLE STAGING TOGGLE: `VUE_COMPAT=true npm run build` routes the
		// runtime `vue` import to @vue/compat (MODE 2 set in main.js's
		// compatConfig) so any un-migrated Vue-2 template-ism keeps working with
		// a console warning while the 98-SFC sweep lands. The default build is
		// PURE Vue 3 — the source is compat-free (no .sync/$set/observable/
		// filters), so the compat runtime is not shipped in a release build.
		'vue$': process.env.VUE_COMPAT === 'true'
			? path.resolve(__dirname, 'node_modules/@vue/compat/dist/vue.runtime.esm-bundler.js')
			: path.resolve(__dirname, 'node_modules/vue/dist/vue.runtime.esm-bundler.js'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): the aliased lib
		// worktree ships its own vue-router, so a per-importer resolve gives
		// @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> scoped
		// slot gets undefined props. One copy = one router.
		'vue-router$': path.resolve(__dirname, 'node_modules/vue-router/dist/vue-router.mjs'),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no
		// main/module, so a directory alias can't resolve it. Point at the
		// explicit entry file (also dedupes the aliased lib worktree's copy).
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
			// STRADDLE STAGING: under `VUE_COMPAT=true` compile SFC templates in
			// @vue/compat MODE 2 so Vue-2 template syntax stays valid during the
			// sweep. The default (pure Vue 3) build sets no compat compiler option.
			...(process.env.VUE_COMPAT === 'true'
				? { options: { compilerOptions: { compatConfig: { MODE: 2 } } } }
				: {}),
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by aliased @conduction/nextcloud-vue components (e.g. CnCard, CnDataTable)
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// PUBLISHED-DIST RUNTIME FIX (nc-vue "vue3 dist" gotcha — RUN the bundle, don't
// grep it). The @conduction/nextcloud-vue dist ships each dual-Vue component as a
// `.vue.js` DISPATCHER that attaches the Vue-3 compiled `render` onto the
// framework-agnostic `.vue2.js` script, wired into the barrel as a SIDE-EFFECT-ONLY
// import:
//     import './components/CnAppRoot/CnAppRoot.vue.js'          // sets script.render = <vue3 render>
//     export { default as CnAppRoot } from './CnAppRoot.vue2.js' // the shared script
// The package's `sideEffects` allowlist covers `**/*.vue` and `**/*.css` but NOT
// these compiled `.vue.js` dispatchers, so webpack's side-effects tree-shaking
// drops the bare dispatcher import for components pulled in only through the
// barrel (CnAppRoot is the app shell). The `script.render = render` assignment
// then never runs and CnAppRoot mounts render-less — a blank `<!---->` comment
// with NO console error (Vue 3 prod). Disabling the side-effects optimization
// keeps every dispatcher import so the Vue-3 renders are attached. procest never
// hit this because it builds against the nc-vue `src` sibling (SFCs compiled by
// vue-loader); an isolated / CI build resolves the published dist and needs this.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	sideEffects: false,
}

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
// Register the exact-match style.css alias BEFORE the bare package alias below:
// enhanced-resolve applies the first matching entry, and the bare alias maps the
// package to its DIRECTORY, so '@nextcloud/dialogs/style.css' (imported by
// nextcloud-vue's useAppInstaller) would resolve to a non-existent root style.css.
// dialogs v6 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css')
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// dialogs v6 drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
// longer auto-polyfills node core modules — without this the bundle fails to emit with
// "Can't resolve 'path'". This app only uses the toast APIs (showError/showSuccess), so
// the FilePicker code path never runs and an empty module is safe.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: false,
}

// @nextcloud/axios is pinned to ~2.5.2 (via package.json overrides) which still
// declares both `import` and `require` exports conditions, so the package can
// be required from @nextcloud/vue's CJS bundle without webpack 5 tripping on
// the exports field. No alias needed; the pin alone is sufficient.

module.exports = webpackConfig
