'use strict';

const fs = require('fs');
const path = require('path');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const failures = [];

function read(relativePath) {
    try {
        return fs.readFileSync(path.join(repositoryRoot, relativePath), 'utf8');
    } catch (error) {
        failures.push(`${relativePath}: file could not be read (${error.message})`);
        return '';
    }
}

function capture(content, pattern, label) {
    const match = content.match(pattern);

    if (!match) {
        failures.push(`${label}: version could not be found`);
        return '';
    }

    return match[1];
}

const plugin = read('nicepay-payment-gateway.php');
const changelog = read('CHANGELOG.md');
const bootstrap = read('tests/bootstrap/bootstrap.php');
const pot = read('languages/nicepay-payment-gateway.pot');
const headerVersion = capture(plugin, /^\s*\*\s*Version:\s*([^\s]+)\s*$/m, 'Plugin header');
const constantVersion = capture(
    plugin,
    /define\(\s*'NICEPAY_VERSION'\s*,\s*'([^']+)'\s*\)/,
    'NICEPAY_VERSION'
);
const changelogVersion = capture(changelog, /^## \[([^\]]+)\](?:\s+-\s+\d{4}-\d{2}-\d{2})?\s*$/m, 'CHANGELOG.md');
const bootstrapVersion = capture(
    bootstrap,
    /define\(\s*'NICEPAY_VERSION'\s*,\s*'([^']+)'\s*\)/,
    'Test bootstrap NICEPAY_VERSION'
);
const potVersion = capture(
    pot,
    /^"Project-Id-Version:\s+NicePay Payment Gateway\s+([^\\]+)\\n"$/m,
    'Translation template Project-Id-Version'
);
const expectedArgument = (process.argv[2] || '').replace(/^v/, '');
const expectedVersion = expectedArgument || headerVersion;
const semanticVersion = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/;
const expectedUpdateUri = 'https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin';
const updateUriMatch = plugin.match(/^\s*\*\s*Update URI:\s*([^\s]+)\s*$/m);
const distributionChannel = process.env.NICEPAY_DISTRIBUTION_CHANNEL || 'github';
const minimumWordPressMatch = plugin.match(/^\s*\*\s*Requires at least:\s*([^\s]+)\s*$/m);

if (!expectedVersion || !semanticVersion.test(expectedVersion)) {
    failures.push(`Expected version is not a supported semantic version: ${expectedVersion || '(empty)'}`);
}

if (!['github', 'wordpress-org-candidate'].includes(distributionChannel)) {
    failures.push(`Unknown NICEPAY_DISTRIBUTION_CHANNEL: ${distributionChannel}`);
} else if (distributionChannel === 'github' && (!updateUriMatch || updateUriMatch[1] !== expectedUpdateUri)) {
    failures.push(`Plugin header Update URI: expected ${expectedUpdateUri}`);
} else if (distributionChannel === 'wordpress-org-candidate' && updateUriMatch && updateUriMatch[1] !== expectedUpdateUri) {
    failures.push(`Plugin header Update URI: unexpected value ${updateUriMatch[1]}`);
}

if (!minimumWordPressMatch || minimumWordPressMatch[1] !== '5.8') {
    failures.push('Plugin header Requires at least must remain 5.8 or be deliberately raised with the support matrix');
}

[
    ['Plugin header', headerVersion],
    ['NICEPAY_VERSION', constantVersion],
    ['CHANGELOG.md latest entry', changelogVersion],
    ['Test bootstrap NICEPAY_VERSION', bootstrapVersion],
    ['Translation template Project-Id-Version', potVersion],
].forEach(([label, value]) => {
    if (value && value !== expectedVersion) {
        failures.push(`${label}: expected ${expectedVersion}, found ${value}`);
    }
});

if (changelogVersion) {
    const escapedVersion = changelogVersion.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const headingPattern = new RegExp(`^## \\[${escapedVersion}\\].*$`, 'm');
    const heading = changelog.match(headingPattern);
    let releaseNotes = '';

    if (heading && typeof heading.index === 'number') {
        const afterHeading = changelog.slice(heading.index + heading[0].length);
        const nextHeadingIndex = afterHeading.search(/^## \[/m);
        releaseNotes = (nextHeadingIndex === -1 ? afterHeading : afterHeading.slice(0, nextHeadingIndex)).trim();
    }

    if (!releaseNotes) {
        failures.push(`CHANGELOG.md: ${changelogVersion} section has no release notes`);
    }
}

if (failures.length > 0) {
    failures.forEach((failure) => {
        console.error(`ERROR: ${failure}`);
    });
    process.exit(1);
}

console.log(`Version consistency check passed: ${expectedVersion}`);
