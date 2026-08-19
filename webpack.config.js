const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const path = require('path');

const bundledPackages = [
    '@wordpress/components',
    '@wordpress/dataviews',
    '@wordpress/element',
    '@wordpress/i18n',
];

// Strip the default-configured DependencyExtractionWebpackPlugin instance —
// we're replacing it with one that knows to bundle our chosen packages.
const filteredPlugins = defaultConfig.plugins.filter(
    (plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

module.exports = {
    ...defaultConfig,
    entry: {
        admin: path.resolve(__dirname, 'src/admin/index.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'build'),
    },
    plugins: [
        ...filteredPlugins,
        new DependencyExtractionWebpackPlugin({
            requestToExternal(request) {
                if (bundledPackages.includes(request)) {
                    return false; // false = force-bundle, skip default externalization
                }
                // returning undefined falls through to the plugin's default behavior
            },
            requestToHandle(request) {
                if (bundledPackages.includes(request)) {
                    return false;
                }
            },
        }),
    ],
};
