import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import pngToIco from 'png-to-ico';
import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(root, 'resources/branding/save-it-mark.svg');
const publicPath = path.join(root, 'public');
const brandPath = path.join(publicPath, 'brand');
const iconsPath = path.join(publicPath, 'icons');
const source = await readFile(sourcePath);

await mkdir(brandPath, { recursive: true });
await mkdir(iconsPath, { recursive: true });
await writeFile(path.join(brandPath, 'save-it-mark.svg'), source);
await writeFile(path.join(publicPath, 'favicon.svg'), source);

async function render(size, output) {
    const buffer = await sharp(source)
        .resize(size, size)
        .png({ compressionLevel: 9, palette: true })
        .toBuffer();
    await writeFile(output, buffer);
    return buffer;
}

const favicon16 = await render(16, path.join(publicPath, 'favicon-16x16.png'));
const favicon32 = await render(32, path.join(publicPath, 'favicon-32x32.png'));
const favicon48 = await render(48, path.join(publicPath, 'favicon-48x48.png'));

await writeFile(
    path.join(publicPath, 'favicon.ico'),
    await pngToIco([favicon16, favicon32, favicon48]),
);
await render(180, path.join(publicPath, 'apple-touch-icon.png'));
await render(192, path.join(iconsPath, 'icon-192.png'));
await render(512, path.join(iconsPath, 'icon-512.png'));

const maskable = await sharp({
    create: {
        width: 512,
        height: 512,
        channels: 4,
        background: '#c8ff3d',
    },
})
    .composite([
        {
            input: await sharp(source).resize(410, 410).toBuffer(),
            left: 51,
            top: 51,
        },
    ])
    .png({ compressionLevel: 9, palette: true })
    .toBuffer();
await writeFile(path.join(iconsPath, 'icon-maskable-512.png'), maskable);

const manifest = {
    name: 'Save It',
    short_name: 'Save It',
    start_url: '/',
    display: 'standalone',
    background_color: '#0b1220',
    theme_color: '#c8ff3d',
    icons: [
        {
            src: '/icons/icon-192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
        },
        {
            src: '/icons/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
        },
        {
            src: '/icons/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
        },
    ],
};

await writeFile(
    path.join(publicPath, 'site.webmanifest'),
    `${JSON.stringify(manifest, null, 2)}\n`,
);
