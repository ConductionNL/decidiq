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

// Use local source only when explicitly opted in, otherwise the npm package.
//
// USE_LOCAL_LIB is opt-IN (ADR-090): building against a developer's working
// checkout is the wrong default for a build that can ship. This app previously
// had NO version check at all, so an unset variable silently built from whatever
// sibling happened to be on disk.
//
// The sibling must satisfy this app's own declared range. It is 2.0.5 today
// against a declared 2.2.0-vue3.16 — a Vue 3 library, but not the version this
// app asked for. That skew breaks the build in a non-obvious way: building from
// the sibling's SOURCE also resolves packages out of the SIBLING's node_modules,
// where a stale vue-demi shim (postinstall picks v2/v2.7/v3 and does not re-run
// on `npm install`) yields
//   export 'default' (imported as 'Vue') was not found in 'vue'
//
// The former CN_NEXTCLOUD_VUE_SRC override is gone. It pointed the build at an
// arbitrary path AND skipped the version check for it, which is precisely the
// hole this guard exists to close — an unmerged branch is exactly the sibling
// most likely to be skewed. Iterating on an unmerged library branch now means
// pointing this app's declared range at that version, so the intent is recorded
// in package.json rather than in one developer's shell.
//
// Fail CLOSED: if the check cannot run, the sibling is refused.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[decidesk] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ 'it does not satisfy this app\'s declared range. Building against the npm dist.',
		)
		useLocalLib = false
	}
}

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
		vue$:
			process.env.VUE_COMPAT === 'true'
				? path.resolve(
						__dirname,
						'node_modules/@vue/compat/dist/vue.runtime.esm-bundler.js',
					)
				: path.resolve(
						__dirname,
						'node_modules/vue/dist/vue.runtime.esm-bundler.js',
					),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): the aliased lib
		// worktree ships its own vue-router, so a per-importer resolve gives
		// @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> scoped
		// slot gets undefined props. One copy = one router.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no
		// main/module, so a directory alias can't resolve it. Point at the
		// explicit entry file (also dedupes the aliased lib worktree's copy).
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
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
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
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
// dialogs ships the stylesheet at dist/style.css behind its "exports" map.
//
// v7 IS ESM-ONLY: its exports map declares only '.' -> ./dist/index.mjs with no
// `main`/`module` fallback, so a DIRECTORY alias no longer resolves (webpack
// applies an exports map to a PACKAGE REQUEST, never to an absolutised path —
// the aliased directory has nothing to resolve against and the build fails with
// "…/node_modules/@nextcloud/dialogs doesn't exist"). Use an exact-match `$`
// alias onto the explicit entry FILE, exactly as `@nextcloud/vue$` above does.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// dialogs drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
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
