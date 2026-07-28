import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = await readFile(path.join(root, 'resources/branding/save-it-mark.svg'));
const publishedSource = await readFile(path.join(root, 'public/brand/save-it-mark.svg'));
assert.deepEqual(publishedSource, source, 'Published logo must match the canonical SVG.');

const githubMark = await readFile(path.join(root, 'public/brand/github-mark.svg'), 'utf8');
assert.match(githubMark, /^<svg[^>]+viewBox="0 0 24 24"/);
assert.match(githubMark, /<path fill="currentColor" d="[^"]+"\/>/);
assert.equal(
    (githubMark.match(/https?:/g) ?? []).length,
    1,
    'GitHub mark may only contain the SVG namespace URL.',
);
assert.equal(githubMark.includes('<script'), false, 'GitHub mark must not contain scripts.');

const expectedImages = new Map([
    ['public/favicon-16x16.png', 16],
    ['public/favicon-32x32.png', 32],
    ['public/favicon-48x48.png', 48],
    ['public/apple-touch-icon.png', 180],
    ['public/icons/icon-192.png', 192],
    ['public/icons/icon-512.png', 512],
    ['public/icons/icon-maskable-512.png', 512],
]);

for (const [relativePath, size] of expectedImages) {
    const metadata = await sharp(path.join(root, relativePath)).metadata();
    assert.equal(metadata.width, size, `${relativePath} width`);
    assert.equal(metadata.height, size, `${relativePath} height`);
}

const social = await sharp(
    path.join(root, 'public/social/save-it-social-card.png'),
).metadata();
assert.equal(social.width, 1200, 'Social preview width');
assert.equal(social.height, 630, 'Social preview height');

const ico = await readFile(path.join(root, 'public/favicon.ico'));
assert.ok(ico.length > 100, 'favicon.ico must not be empty.');

const manifest = JSON.parse(
    await readFile(path.join(root, 'public/site.webmanifest'), 'utf8'),
);
assert.equal(manifest.name, 'Save It');
assert.equal(manifest.short_name, 'Save It');
assert.equal(manifest.start_url, '/');
assert.equal(manifest.icons.length, 3);

console.log('Icon validation passed.');
