'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const javascriptRoot = path.join(repositoryRoot, 'assets', 'js');
const expectedFilenames = [
    'nicepay-admin.js',
    'nicepay-blocks.js',
    'nicepay-shortcode-admin.js',
    'nicepay.js'
];
// nosemgrep: javascript_pathtraversal_rule-non-literal-fs-filename -- javascriptRoot is derived only from this script's directory and fixed segments.
const discoveredFilenames = fs.readdirSync(javascriptRoot, { withFileTypes: true })
    .filter((entry) => entry.isFile() && entry.name.endsWith('.js'))
    .map((entry) => entry.name)
    .sort();

if (JSON.stringify(discoveredFilenames) !== JSON.stringify(expectedFilenames)) {
    console.error('ERROR: assets/js allowlist is stale; update check-js.js for the current JavaScript files');
    process.exit(1);
}

const files = [
    path.join(repositoryRoot, 'assets', 'js', 'nicepay-admin.js'),
    path.join(repositoryRoot, 'assets', 'js', 'nicepay-blocks.js'),
    path.join(repositoryRoot, 'assets', 'js', 'nicepay-shortcode-admin.js'),
    path.join(repositoryRoot, 'assets', 'js', 'nicepay.js')
];

for (const file of files) {
    const result = spawnSync(process.execPath, ['--check', file], { stdio: 'inherit' });

    if (result.status !== 0) {
        process.exit(result.status || 1);
    }
}

console.log(`JavaScript syntax check passed: ${files.length} file(s)`);
