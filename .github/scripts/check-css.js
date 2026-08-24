'use strict';

const fs = require('node:fs');
const path = require('node:path');
const csstree = require('css-tree');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const assetsRoot = path.join(repositoryRoot, 'assets');

function collectCssFiles(directory) {
    return fs.readdirSync(directory, { withFileTypes: true })
        .flatMap((entry) => {
            const absolutePath = path.join(directory, entry.name);
            if (entry.isDirectory()) return collectCssFiles(absolutePath);
            return entry.isFile() && entry.name.endsWith('.css') ? [absolutePath] : [];
        })
        .sort();
}

const files = collectCssFiles(assetsRoot);
if (files.length === 0) {
    console.error('ERROR: no CSS files were found under assets');
    process.exit(1);
}

for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    try {
        csstree.parse(source, { filename: file, positions: true });
    } catch (error) {
        console.error(`ERROR: invalid CSS in ${file}`);
        console.error(error.formattedMessage || error.message);
        process.exit(1);
    }
}

console.log(`CSS parse check passed: ${files.length} file(s)`);
