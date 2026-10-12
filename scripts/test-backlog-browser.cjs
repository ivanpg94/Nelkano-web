/* Local browser integration. Requires the bundled Playwright or NODE_PATH. */
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const runFixture = action => execFileSync('docker', [
  'compose', 'exec', '-T', '-e', 'NELKANO_LOCAL_BACKLOG_BROWSER_TEST=1',
  '-e', 'NELKANO_BACKLOG_BROWSER_ACTION=' + action,
  'drupal', 'vendor/bin/drush', 'php:script',
  'web/modules/custom/nelkano_home/tests/backlog-browser-fixture.php'
], {encoding: 'utf8'});
(async () => {
  let browser;
  let prepared = false;
  const errors = [];
  try {
    const data = runFixture('prepare');
    prepared = true;
    const fixture = JSON.parse(data.split('FIXTURE:')[1].trim());
    browser = await chromium.launch({headless: true, channel: process.env.NELKANO_TEST_BROWSER || 'chrome'});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    let confirmation = null;
    let deleteRequests = 0;
    page.on('dialog', dialog => {
      if (dialog.type() === 'confirm' && confirmation && dialog.message().startsWith('¿Eliminar la HU ')) {
        const action = confirmation; confirmation = null;
        if (action === 'accept') dialog.accept(); else dialog.dismiss();
      } else { errors.push(dialog.message()); dialog.dismiss(); }
    });
    page.on('request', request => { if (request.method() === 'POST' && request.url().includes('/delete')) deleteRequests++; });
    let navigations = 0;
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations++; });
    await page.goto('http://localhost:8088' + fixture.login);
    await page.goto('http://localhost:8088/admin/nelkano/backlog?q=' + fixture.prefix);
    // The fixture deliberately lacks "view the administration theme".
    // Its page must still use the Nelkano shell without Olivero's chrome.
    assert.equal(await page.evaluate(() => drupalSettings.ajaxPageState.theme), 'olivero');
    assert.equal(await page.locator('main.nk-admin-page').count(), 1);
    assert.equal(await page.locator('.site-header, .site-footer, .region-breadcrumb').count(), 0);
    assert.equal(await page.locator('.nk-admin-app').count(), 1);
    assert.equal(await page.locator('main.nk-admin-page header').count(), 1);
    const initialNavigations = navigations;
    const search = page.locator('#nk-hu-search');
    const sprint = page.locator('#nk-sprint-filter');
    const cards = page.locator('.nk-hu-card');
    const settled = () => page.waitForFunction(() => !document.querySelector('#nk-backlog-results[aria-busy="true"]'));
    assert.equal(await cards.count(), 3);
    await sprint.selectOption('0');
    await page.waitForFunction(() => document.querySelectorAll('.nk-hu-card').length === 1 && !document.querySelector('#nk-backlog-results[aria-busy="true"]'));
    assert.match(await page.locator('.nk-hu-sprint').textContent(), /Sprint 0/);
    assert.equal(await page.locator('#nk-backlog-total').textContent(), '1');
    assert.match(page.url(), /sprint=0/);
    await search.fill(fixture.prefix + ' emulacion');
    await page.waitForURL(url => url.searchParams.get('q') === fixture.prefix + ' emulacion');
    await settled();
    assert.equal(await cards.count(), 1);
    assert.equal(await search.evaluate(input => input === document.activeElement), true);
    assert.equal(await page.locator('.nk-hu-column').count(), 4);
    // Existing status behavior must attach to cards inserted by Drupal AJAX.
    await page.locator('.nk-hu-state').selectOption('in_progress');
    await page.waitForFunction(() => document.querySelector('.nk-hu-column[data-status="in_progress"] .nk-hu-card') && !document.querySelector('.nk-hu-card[aria-busy="true"]'));
    await search.fill(fixture.prefix + ' sincronizacion');
    await page.waitForURL(url => url.searchParams.get('q') === fixture.prefix + ' sincronizacion');
    await settled();
    assert.equal(await page.locator('.nk-hu-column[data-status="in_progress"] .nk-hu-card').count(), 1);
    // Queue successive changes while an earlier response is delayed.
    await page.route('**/admin/nelkano/backlog?**', async route => {
      if (route.request().resourceType() !== 'document') {
        await new Promise(resolve => setTimeout(resolve, 450));
      }
      await route.continue();
    });
    await search.fill(fixture.prefix + ' absent');
    await page.waitForFunction(() => document.querySelector('#nk-backlog-results[aria-busy="true"]'));
    await search.fill(fixture.prefix);
    await sprint.selectOption('none');
    await page.waitForURL(url => url.searchParams.get('q') === fixture.prefix && url.searchParams.get('sprint') === 'none');
    await settled();
    assert.equal(await cards.count(), 1);
    assert.match(await page.locator('.nk-hu-sprint').textContent(), /Sin sprint/);
    await page.unroute('**/admin/nelkano/backlog?**');
    // A failed request retains the old board and allows retry.
    const oldUrl = page.url();
    await page.route('**/admin/nelkano/backlog?**', route => route.fulfill({status: 500, body: 'Synthetic failure'}));
    await search.fill(fixture.prefix + ' absent');
    await page.waitForFunction(() => document.querySelector('.nk-backlog-feedback.is-error'));
    await settled();
    assert.equal(await cards.count(), 1);
    assert.equal(page.url(), oldUrl);
    await page.unroute('**/admin/nelkano/backlog?**');
    await page.locator('[data-nk-backlog-clear]').click();
    await page.waitForURL(url => !url.searchParams.has('q') && !url.searchParams.has('sprint'));
    await settled();
    assert.ok(await cards.count() >= 3);
    assert.equal(await search.inputValue(), '');
    assert.equal(await sprint.inputValue(), '');
    assert.equal(await page.locator('[data-nk-backlog-clear]').isVisible(), false);
    assert.equal(navigations, initialNavigations, 'Filtering never navigates the page');
    assert.deepEqual(errors, []);
    fs.mkdirSync('outputs', {recursive: true});
    await page.screenshot({path: 'outputs/backlog-search-desktop.png', fullPage: true});
    await page.setViewportSize({width: 390, height: 844});
    await page.screenshot({path: 'outputs/backlog-search-mobile.png', fullPage: true});
    // Delete only a known synthetic HU, retaining its combined filters.
    await page.setViewportSize({width: 1440, height: 1000});
    await search.fill(fixture.prefix);
    await sprint.selectOption('2');
    await page.waitForFunction(() => document.querySelectorAll('.nk-hu-card').length === 1 && !document.querySelector('#nk-backlog-results[aria-busy="true"]'));
    const deleting = page.locator('.nk-hu-card');
    assert.equal(await deleting.getAttribute('data-id'), String(fixture.ids[2]));
    const filterUrl = page.url();
    const beforeDelete = deleteRequests;
    confirmation = 'dismiss';
    await deleting.locator('.nk-hu-delete').click();
    assert.equal(await cards.count(), 1);
    assert.equal(deleteRequests, beforeDelete, 'cancel never sends a deletion request');
    assert.equal(confirmation, null);
    // A failed deletion retains the card and permits retry.
    await page.route('**/admin/nelkano/backlog/*/delete', route => route.fulfill({status: 500, body: 'Synthetic deletion failure'}));
    confirmation = 'accept';
    await deleting.locator('.nk-hu-delete').click();
    await page.waitForFunction(() => document.querySelector('.nk-backlog-feedback.is-error') && !document.querySelector('.nk-hu-card[aria-busy="true"]'));
    assert.equal(await cards.count(), 1);
    assert.equal(await deleting.locator('.nk-hu-delete').isDisabled(), false);
    await page.unroute('**/admin/nelkano/backlog/*/delete');
    confirmation = 'accept';
    await deleting.locator('.nk-hu-delete').click();
    await page.waitForFunction(() => document.querySelectorAll('.nk-hu-card').length === 0);
    assert.match(await page.locator('.nk-backlog-feedback').textContent(), /HU .* eliminada/);
    assert.equal(await page.locator('#nk-backlog-total').textContent(), '0');
    assert.equal(await page.locator('.nk-hu-column[data-status="new"] .nk-hu-count').textContent(), '0');
    assert.equal(await page.locator('.nk-hu-column[data-status="new"] .nk-hu-empty').isVisible(), true);
    assert.equal(page.url(), filterUrl);
    assert.equal(await search.inputValue(), fixture.prefix);
    assert.equal(await sprint.inputValue(), '2');
    assert.equal(navigations, initialNavigations, 'deletion does not navigate');
    // A fresh Search API response must not resurrect the removed card.
    await page.locator('[data-nk-backlog-filter] button[type="submit"]').click();
    await settled();
    assert.equal(await cards.count(), 0);
    // The same template must also work with Drupal's real admin theme.
    runFixture('admin-theme');
    await page.reload();
    assert.equal(await page.evaluate(() => drupalSettings.ajaxPageState.theme), 'claro');
    assert.equal(await page.locator('main.nk-admin-page').count(), 1);
    assert.equal(await page.locator('.site-header, .site-footer, .region-breadcrumb').count(), 0);
    assert.equal(await page.locator('.nk-app-nav').count(), 1);
    await page.locator('.nk-hu-create').click();
    assert.equal(await page.locator('main.nk-admin-page').count(), 1);
    assert.equal(await page.locator('.nk-app-nav').count(), 1);
    assert.equal(await page.locator('input[name="title"]').count(), 1);
    assert.deepEqual(errors, []);
    console.log('PASS: live AJAX search/sprint/clear, focus, movement after refresh, queued requests, error/retry, confirmed deletion/cancel/retry/counts with preserved filters and zero navigations.');
  } finally {
    if (browser) await browser.close();
    if (prepared) runFixture('cleanup');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
