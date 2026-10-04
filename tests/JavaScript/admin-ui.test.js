import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const layout = readFileSync(new URL('../../resources/views/admin/layout.blade.php', import.meta.url), 'utf8');
const email = readFileSync(new URL('../../resources/views/admin/email.blade.php', import.meta.url), 'utf8');
const security = readFileSync(new URL('../../resources/views/admin/security.blade.php', import.meta.url), 'utf8');
const geoip = readFileSync(new URL('../../resources/views/admin/geoip.blade.php', import.meta.url), 'utf8');

test('admin shell provides a balanced mobile navigation and iPhone safe areas', () => {
    assert.match(layout, /viewport-fit=cover/);
    assert.match(layout, /env\(safe-area-inset-top\)/);
    assert.match(layout, /env\(safe-area-inset-bottom\)/);
    assert.match(layout, /grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/);
    assert.match(layout, /overflow-x:hidden/);
});

test('email and security actions use the shared responsive action layout', () => {
    assert.match(layout, /\.action-grid/);
    assert.match(email, /class="action-grid"/);
    assert.match(security, /class="action-grid"/);
});

test('GeoIP trusted header mode supplies a default without replacing a custom header', () => {
    assert.match(geoip, /data-geoip-mode/);
    assert.match(geoip, /data-geoip-header/);
    const script = geoip.match(/<script>([\s\S]*?)<\/script>/)[1];
    let onChange;
    const mode = { value: 'none', addEventListener(event, callback) { assert.equal(event, 'change'); onChange = callback; } };
    const header = { value: '' };

    runInNewContext(script, { document: { querySelector: (selector) => selector === '[data-geoip-mode]' ? mode : header } });

    mode.value = 'trusted_header';
    onChange();
    assert.equal(header.value, 'CF-IPCountry');

    header.value = 'X-Country-Code';
    onChange();
    assert.equal(header.value, 'X-Country-Code');
});
