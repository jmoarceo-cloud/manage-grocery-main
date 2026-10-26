export async function useRecoveryCode(page, code) {
    const backup = page.locator('details.backup-box');
    if (!(await backup.evaluate(element => element.open))) await backup.locator('summary').click();
    await page.getByLabel('Backup code', { exact: true }).fill(code);
    await page.getByRole('button', { name: 'Use backup code', exact: true }).click();
}
