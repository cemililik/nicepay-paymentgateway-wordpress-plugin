'use strict';

const fs = require('fs');

const catalogs = process.argv.slice(2);
if (catalogs.length === 0) {
    process.stderr.write('Usage: check-po-placeholders.js <catalog.po> [...]\n');
    process.exit(2);
}

function decodeQuoted(line, file, lineNumber) {
    const quote = line.indexOf('"');
    if (quote === -1) {
        throw new Error(`${file}:${lineNumber}: malformed PO string`);
    }
    return JSON.parse(line.slice(quote));
}

function parseCatalog(file) {
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    const entries = [];
    let entry = null;
    let activeField = null;

    function finishEntry() {
        if (entry && Object.hasOwn(entry, 'msgid')) {
            entries.push(entry);
        }
        entry = null;
        activeField = null;
    }

    lines.forEach((line, index) => {
        const lineNumber = index + 1;
        if (line === '') {
            finishEntry();
            return;
        }
        if (line.startsWith('#~')) {
            return;
        }
        if (line.startsWith('#')) {
            if (!entry) entry = { line: lineNumber };
            return;
        }

        const field = line.match(/^(msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+/);
        if (field) {
            if (!entry) entry = { line: lineNumber };
            activeField = field[1];
            entry[activeField] = decodeQuoted(line, file, lineNumber);
            return;
        }

        if (/^\s*"/.test(line) && entry && activeField) {
            entry[activeField] += decodeQuoted(line.trimStart(), file, lineNumber);
            return;
        }

        throw new Error(`${file}:${lineNumber}: unsupported PO syntax`);
    });
    finishEntry();
    return entries;
}

function placeholders(value) {
    return (value.match(/%(?:\d+\$)?[-+0# ']*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxXdi]/g) || []).sort();
}

function htmlTags(value) {
    const tags = [];
    const expression = /<(\/)?([A-Za-z][A-Za-z0-9:-]*)\b[^>]*>/g;
    let match = expression.exec(value);
    while (match !== null) {
        tags.push(`${match[1] ? '/' : ''}${match[2].toLowerCase()}`);
        match = expression.exec(value);
    }
    return tags.sort();
}

function sameMembers(left, right) {
    return JSON.stringify(left) === JSON.stringify(right);
}

let failures = 0;
catalogs.forEach((file) => {
    parseCatalog(file).forEach((entry) => {
        if (!entry.msgid) return;

        Object.keys(entry)
            .filter((key) => key === 'msgstr' || /^msgstr\[\d+\]$/.test(key))
            .forEach((key) => {
                const translation = entry[key];
                if (!translation) return;

                const pluralIndex = key.match(/^msgstr\[(\d+)\]$/);
                const source = pluralIndex && Number(pluralIndex[1]) > 0 && entry.msgid_plural
                    ? entry.msgid_plural
                    : entry.msgid;

                if (!sameMembers(placeholders(source), placeholders(translation))) {
                    process.stderr.write(`${file}:${entry.line}: placeholder mismatch for ${JSON.stringify(entry.msgid)}\n`);
                    failures += 1;
                }
                if (!sameMembers(htmlTags(source), htmlTags(translation))) {
                    process.stderr.write(`${file}:${entry.line}: HTML tag mismatch for ${JSON.stringify(entry.msgid)}\n`);
                    failures += 1;
                }
            });

        if (entry.msgid === 'Pay securely via NicePay (Credit Card, Bank Transfer, or Mobile).') {
            const translation = entry.msgstr || '';
            if (/virtual account|sanal hesap|가상계좌|虚拟账户/iu.test(translation)) {
                process.stderr.write(`${file}:${entry.line}: unsupported Virtual Account claim remains in the active gateway description\n`);
                failures += 1;
            }
        }
    });
});

if (failures > 0) {
    process.exit(1);
}

process.stdout.write('PO placeholder, HTML, and certified-feature checks passed.\n');
