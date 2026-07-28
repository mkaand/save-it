import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import pngToIco from 'png-to-ico';
import * as fontkit from 'fontkit';
import sharp from 'sharp';
import { siGithub } from 'simple-icons';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(root, 'resources/branding/save-it-mark.svg');
const publicPath = path.join(root, 'public');
const brandPath = path.join(publicPath, 'brand');
const iconsPath = path.join(publicPath, 'icons');
const socialPath = path.join(publicPath, 'social');
const source = await readFile(sourcePath);
const socialTemplate = await readFile(
    path.join(root, 'resources/branding/save-it-social-card.svg'),
    'utf8',
);

await mkdir(brandPath, { recursive: true });
await mkdir(iconsPath, { recursive: true });
await mkdir(socialPath, { recursive: true });
await writeFile(path.join(brandPath, 'save-it-mark.svg'), source);
await writeFile(path.join(publicPath, 'favicon.svg'), source);
await writeFile(
    path.join(brandPath, 'github-mark.svg'),
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-label="GitHub"><path fill="currentColor" d="${siGithub.path}"/></svg>\n`,
);

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
function textPath(text, { x, baseline, size, weight, fill, letterSpacing = 0 }) {
    const fontPath = path.join(
        root,
        `node_modules/@fontsource/inter/files/inter-latin-${weight}-normal.woff2`,
    );
    const font = fontkit.openSync(fontPath);
    const run = font.layout(text);
    const scale = size / font.unitsPerEm;
    let cursor = 0;
    const glyphs = run.glyphs.map((glyph, index) => {
        const position = run.positions[index];
        const transformX = x + (cursor + position.xOffset) * scale;
        const transformY = baseline - position.yOffset * scale;
        cursor += position.xAdvance + letterSpacing / scale;
        return `<path d="${glyph.path.toSVG()}" transform="translate(${transformX.toFixed(3)} ${transformY.toFixed(3)}) scale(${scale.toFixed(6)} ${(-scale).toFixed(6)})"/>`;
    });

    return `<g fill="${fill}" aria-label="${text}">${glyphs.join('')}</g>`;
}

const socialTextPaths = [
    textPath('Save It', {
        x: 330,
        baseline: 222,
        size: 96,
        weight: 800,
        fill: '#f5f7fb',
        letterSpacing: -4,
    }),
    textPath('Download media. Keep it simple.', {
        x: 112,
        baseline: 390,
        size: 54,
        weight: 700,
        fill: '#f5f7fb',
    }),
    textPath('Fast, privacy-conscious media analysis. No account required.', {
        x: 112,
        baseline: 458,
        size: 29,
        weight: 400,
        fill: '#a9b5c8',
    }),
    textPath('save.allmy.win', {
        x: 145,
        baseline: 548,
        size: 24,
        weight: 700,
        fill: '#0b1220',
    }),
].join('\n  ');

const socialSource = socialTemplate.replace('<!-- SOCIAL_TEXT_PATHS -->', socialTextPaths);
if (socialSource.includes('<text') || socialSource.includes('SOCIAL_TEXT_PATHS')) {
    throw new Error('Social card text must be converted to deterministic SVG paths.');
}
await writeFile(
    path.join(socialPath, 'save-it-social-card-v2.svg'),
    `${socialSource.trimEnd()}\n`,
);
await writeFile(
    path.join(socialPath, 'save-it-social-card-v2.png'),
    await sharp(Buffer.from(socialSource))
        .resize(1200, 630)
        .png({ compressionLevel: 9 })
        .toBuffer(),
);

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
