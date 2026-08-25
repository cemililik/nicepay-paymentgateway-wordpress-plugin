'use strict';

const fs = require('node:fs');
const path = require('node:path');
const csstree = require('css-tree');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const cssRoot = path.join(repositoryRoot, 'assets', 'css');
const expectedFilenames = ['nicepay-admin.css', 'nicepay.css'];
// nosemgrep: javascript_pathtraversal_rule-non-literal-fs-filename -- cssRoot is derived only from this script's directory and fixed segments.
const discoveredFilenames = fs.readdirSync(cssRoot, { withFileTypes: true })
    .filter((entry) => entry.isFile() && entry.name.endsWith('.css'))
    .map((entry) => entry.name)
    .sort();

if (JSON.stringify(discoveredFilenames) !== JSON.stringify(expectedFilenames)) {
    console.error('ERROR: assets/css allowlist is stale; update check-css.js for the current CSS files');
    process.exit(1);
}

const files = [
    {
        filename: 'assets/css/nicepay-admin.css',
        source: fs.readFileSync(path.join(repositoryRoot, 'assets', 'css', 'nicepay-admin.css'), 'utf8')
    },
    {
        filename: 'assets/css/nicepay.css',
        source: fs.readFileSync(path.join(repositoryRoot, 'assets', 'css', 'nicepay.css'), 'utf8')
    }
];

for (const file of files) {
    try {
        csstree.parse(file.source, { filename: file.filename, positions: true });
    } catch (error) {
        console.error(`ERROR: invalid CSS in ${file.filename}`);
        console.error(error.formattedMessage || error.message);
        process.exit(1);
    }
}

console.log(`CSS parse check passed: ${files.length} file(s)`);
