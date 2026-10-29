const path = require( 'path' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const blocks = [ 'location-finder', 'term-locations', 'nearest-location' ];

// Minimal webpack config per block — one compilation per block so each
// block gets its own build/ directory with index.js + index.asset.php.
// Using a standalone config (not extending @wordpress/scripts defaultConfig)
// to avoid conflicts with its dynamic entry function and plugin chain.
module.exports = blocks.map( ( blockName ) => ( {
	mode:   process.env.NODE_ENV === 'development' ? 'development' : 'production',
	target: 'web',
	devtool: process.env.NODE_ENV === 'development' ? 'source-map' : false,

	entry: path.resolve( __dirname, `blocks/${ blockName }/index.js` ),

	output: {
		path:     path.resolve( __dirname, `blocks/${ blockName }/build` ),
		filename: 'index.js',
	},

	module: {
		rules: [
			{
				test:    /\.(js|jsx)$/,
				exclude: /node_modules/,
				use:     {
					loader:  require.resolve( 'babel-loader' ),
					options: {
						presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
						cacheDirectory: true,
					},
				},
			},
		],
	},

	resolve: {
		extensions: [ '.js', '.jsx' ],
		alias: {
			'lodash-es': 'lodash',
		},
	},

	plugins: [
		// Generates index.asset.php alongside index.js with a version hash and
		// the list of WordPress script handles this block depends on.
		new DependencyExtractionWebpackPlugin(),
	],

	// WordPress packages are loaded as globals on the window object; map
	// import statements to the corresponding window.wp.* references.
	externals: {
		react:     'React',
		'react-dom': 'ReactDOM',
	},
} ) );
