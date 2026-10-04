import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { analysisFailureReportContext } from '../../resources/js/issue-report.js';

const welcome = readFileSync(new URL('../../resources/views/welcome.blade.php', import.meta.url), 'utf8');
const analyzer = readFileSync(new URL('../../resources/js/analyzer.js', import.meta.url), 'utf8');
const issueReport = readFileSync(new URL('../../resources/js/issue-report.js', import.meta.url), 'utf8');

test('issue reporting keeps only an allowlisted failed-analysis context', () => {
    assert.deepEqual(analysisFailureReportContext({
        submittedUrl: 'https://example.test/media',
        error: { provider: 'facebook', code: 'upstream_unavailable', request_id: 'safe_123', token: 'secret' },
    }), {
        submittedUrl: 'https://example.test/media', provider: 'facebook', errorCode: 'upstream_unavailable', requestId: 'safe_123',
    });
    assert.deepEqual(analysisFailureReportContext({
        submittedUrl: 'https://example.test/media', error: { provider: 'evil', code: 'bad code', request_id: 'token=secret' },
    }), {
        submittedUrl: 'https://example.test/media', provider: null, errorCode: null, requestId: null,
    });
});

test('issue report UI exposes both normal and failed-analysis launchers without changing sacred controls', () => {
    assert.match(welcome, /data-issue-report-open/);
    assert.match(welcome, /data-issue-report-failed-open/);
    assert.match(welcome, /data-issue-report-dialog/);
    assert.match(welcome, /data-paste-button/);
    assert.match(welcome, /data-theme-toggle/);
    assert.match(welcome, /data-platform-icon="youtube"/);
    assert.match(welcome, /data-facebook-extractor-available/);
    assert.match(analyzer, /save-it:analysis-failed/);
    assert.match(analyzer, /analysisFailureReportContext\(\{ submittedUrl, error: payload\?\.error \}\)/);
    assert.match(issueReport, /X-CSRF-TOKEN/);
    assert.match(issueReport, /if \(sending\)/);
    assert.match(issueReport, /Thanks\. Your report has been sent\./);
});
