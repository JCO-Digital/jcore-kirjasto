const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		folders: './src/folders/index.js',
		'usage-page': './src/usage-page/index.js',
		'usage-media': './src/usage-media/index.js',
	},
};
