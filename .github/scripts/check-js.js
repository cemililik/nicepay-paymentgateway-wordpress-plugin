'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const assetsRoot = path.join(repositoryRoot, 'assets', 'js');

function collectJavaScriptFiles(directory) {
    return fs.readdirSync(directory, { withFileTypes: true })
        .flatMap((entry) => {
            const absolutePath = path.join(directory, entry.name);

            if (entry.isDirectory()) {
                return collectJavaScriptFiles(absolutePath);
            }

            return entry.isFile() && entry.name.endsWith('.js') ? [absolutePath] : [];
        })
        .sort();
}

const files = collectJavaScriptFiles(assetsRoot);

if (files.length === 0) {
    console.error('ERROR: no JavaScript files were found under assets/js');
    process.exit(1);
}

for (const file of files) {
    const result = spawnSync(process.execPath, ['--check', file], { stdio: 'inherit' });

    if (result.status !== 0) {
        process.exit(result.status || 1);
    }
}

console.log(`JavaScript syntax check passed: ${files.length} file(s)`);
