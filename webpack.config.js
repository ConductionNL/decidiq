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
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
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

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// @nextcloud/axios is pinned to ~2.5.2 (via package.json overrides) which still
// declares both `import` and `require` exports conditions, so the package can
// be required from @nextcloud/vue's CJS bundle without webpack 5 tripping on
// the exports field. No alias needed; the pin alone is sufficient.

module.exports = webpackConfig
