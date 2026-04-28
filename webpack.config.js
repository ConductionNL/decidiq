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
}

// Webpack 5 'auto' detects the public path from the entry script's src at
// runtime. The base @nextcloud/webpack-vue-config hardcodes
// `/apps/<appId>/js/`, which only resolves correctly in production where
// the appstore serves it. In nextcloud-docker-dev the app is bind-mounted
// at /custom_apps/<appId> and its main.js is loaded from there, so
// hardcoded chunks 404 with text/html (NC serves the app index for
// /apps/<appId>/* paths). 'auto' makes dev and prod resolve correctly
// without any wrapper config.
webpackConfig.output.publicPath = 'auto'

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

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
			// Required when USE_LOCAL_LIB resolves @conduction/nextcloud-vue
			// to the library source: a small number of components ship
			// `<style lang="scss">` blocks that are pre-compiled by Rollup
			// in the published package but need a loader here.
			test: /\.s[ac]ss$/i,
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

module.exports = webpackConfig
