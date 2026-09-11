const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const path = require('path');

const bundledPackages = [
    '@wordpress/components',
    '@wordpress/dataviews',
    '@wordpress/element',
];

// @wordpress/i18n is intentionally left out of bundledPackages: it must stay
// externalized to the "wp-i18n" script handle so wp_set_script_translations()
// can supply translations to the same runtime instance this bundle calls
// __()/sprintf() on. Bundling a private copy would silently break translation
// loading — the calls would run against an isolated instance WordPress never
// sends locale data to.

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
