import './bootstrap';
import { initPage } from './analyzer.js';
import { initTheme } from './theme.js';

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initPage();
});
