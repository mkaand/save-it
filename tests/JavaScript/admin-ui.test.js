import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const layout = readFileSync(new URL('../../resources/views/admin/layout.blade.php', import.meta.url), 'utf8');
const email = readFileSync(new URL('../../resources/views/admin/email.blade.php', import.meta.url), 'utf8');
const security = readFileSync(new URL('../../resources/views/admin/security.blade.php', import.meta.url), 'utf8');

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
