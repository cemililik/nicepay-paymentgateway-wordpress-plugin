#!/usr/bin/env node

'use strict';

const fs = require('fs');

const reportPath = process.argv[2];
const minimum = Number(process.argv[3]);

if (!reportPath || !Number.isFinite(minimum) || minimum < 0 || minimum > 100) {
    console.error('Usage: check-coverage.js <clover.xml> <minimum-percent>');
    process.exit(2);
}

let report;
try {
    report = fs.readFileSync(reportPath, 'utf8');
} catch (error) {
    console.error(`ERROR: coverage report cannot be read: ${error.message}`);
    process.exit(1);
}

const project = report.match(/<project\b[\s\S]*?<metrics\s+([^>]+)\/>\s*<\/project>/);
if (!project) {
    console.error('ERROR: Clover project metrics are missing.');
    process.exit(1);
}

const attributes = Object.fromEntries(
    Array.from(project[1].matchAll(/([a-z]+)="(\d+)"/g), (match) => [match[1], Number(match[2])])
);
const statements = attributes.statements;
const covered = attributes.coveredstatements;

if (!Number.isInteger(statements) || statements < 1 || !Number.isInteger(covered)) {
    console.error('ERROR: Clover statement metrics are invalid.');
    process.exit(1);
}

const percentage = (covered * 100) / statements;
console.log(`Line coverage: ${percentage.toFixed(2)}% (${covered}/${statements}); required: ${minimum.toFixed(2)}%`);

if (percentage + Number.EPSILON < minimum) {
    console.error('ERROR: line coverage regressed below the measured baseline.');
    process.exit(1);
}
