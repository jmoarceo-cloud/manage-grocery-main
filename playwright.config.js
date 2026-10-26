import { defineConfig } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';

const temp = path.resolve('storage/testing/browser-temp');
fs.mkdirSync(temp, { recursive: true });
process.env.TEMP = temp;
process.env.TMP = temp;
process.env.PLAYWRIGHT_BROWSERS_PATH = path.resolve('storage/tooling/browsers');

export default defineConfig({
    testDir: './tests/browser',
    workers: 1,
    timeout: 60000,
    outputDir: 'storage/testing/playwright-results',
    reporter: [['line'], ['json', { outputFile: 'docs/audit/UI_SMOKE.json' }]],
    use: { browserName: 'chromium', headless: true, trace: 'off', video: 'off' },
    webServer: {
        command: 'php -S 127.0.0.1:8017 -t public bin/qa-router.php',
        url: 'http://127.0.0.1:8017/__qa/target',
        reuseExistingServer: true,
        env: { APP_ENV: 'testing', MAIL_MAILER: 'array', SESSION_PATH: '/', APP_URL: 'http://127.0.0.1:8017' },
        timeout: 30000,
    },
});
