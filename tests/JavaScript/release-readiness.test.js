import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const layout = readFileSync(new URL('../../resources/views/admin/layout.blade.php', import.meta.url), 'utf8');
const reports = readFileSync(new URL('../../resources/views/admin/reports.blade.php', import.meta.url), 'utf8');
const envExample = readFileSync(new URL('../../.env.example', import.meta.url), 'utf8');

test('reports time input has a bounded iOS-safe mobile layout', () => {
    assert.match(reports, /type="time"/);
    assert.match(layout, /input\[type=time\]\{appearance:none;-webkit-appearance:none;min-inline-size:0;inline-size:100%;max-inline-size:100%\}/);
    assert.match(layout, /overflow-x:hidden/);
});

test('admin mobile viewport keeps a three-column nav and safe areas', () => {
    assert.match(layout, /env\(safe-area-inset-top\)/);
    assert.match(layout, /env\(safe-area-inset-bottom\)/);
    assert.match(layout, /grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/);
});

test('portable environment example has no production host or secret', () => {
    assert.match(envExample, /APP_URL=http:\/\/localhost/);
    assert.match(envExample, /TURNSTILE_ENABLED=false/);
    assert.doesNotMatch(envExample, /save\.allmy\.win|APP_KEY=.+/);
});
