const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		folders: './src/folders/index.js',
		'usage-page': './src/usage-page/index.js',
		'usage-media': './src/usage-media/index.js',
		replace: './src/replace/index.js',
		'duplicates-page': './src/duplicates-page/index.js',
		'duplicates-upload': './src/duplicates-upload/index.js',
	},
};
