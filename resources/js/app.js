import './bootstrap';
import { initPage } from './analyzer.js';
import { initTheme } from './theme.js';
import { initIssueReport } from './issue-report.js';

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initPage();
    initIssueReport();
});
