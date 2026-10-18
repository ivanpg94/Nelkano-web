// Read-only headless QA. Uses an isolated browser profile and no user session.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
  const base = process.env.NELKANO_GUIDE_BASE_URL || 'http://localhost:8088';
  const origin = new URL(base).origin;
  const output = process.env.NELKANO_GUIDE_QA_OUTPUT;
  const browser = await chromium.launch({headless:true, channel:process.env.NELKANO_TEST_BROWSER || 'chrome'});
  const results = [];
  try {
    for (const route of ['/guia', '/en/guide']) {
      for (const viewport of [{width:1440,height:1050}, {width:390,height:844}]) {
        const context = await browser.newContext({viewport});
        await context.route('**/*', request => new URL(request.request().url()).origin === origin ? request.continue() : request.abort());
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
          const response = await page.goto(base + route, {waitUntil:'networkidle'});
          assert.equal(response.status(), 200);
          const reject = page.locator('[data-consent-reject]');
          if (await reject.isVisible()) await reject.click();
          const guide = page.locator('.r-usage-guide');
          assert.equal(await guide.count(), 1, 'usage layout is scoped');
          const groups = guide.locator('.r-guide-group');
          assert.equal(await groups.count(), 6, 'six task groups');
          const toc = guide.locator('.r-guide-toc');
          const links = toc.locator('a[href^="#"]');
          assert.equal(await links.count(), 6, 'index groups the guide into six topics');
          assert.equal(await guide.locator('details, summary').count(), 0, 'guide has no accordion controls');
          assert.ok(await toc.isVisible(), 'index is always visible');
          const visibleTasks = await guide.locator('.r-guide-task').evaluateAll(nodes => nodes.filter(node => node.checkVisibility()).length);
          assert.equal(visibleTasks, 17, 'all seventeen tasks are always visible');
          const initialHeight = await guide.evaluate(element => element.getBoundingClientRect().height);
          const firstTaskHeight = await guide.locator('.r-guide-task').first().evaluate(element => element.getBoundingClientRect().height);
          if (viewport.width >= 1024) assert.ok(firstTaskHeight <= 450, `first task fits compactly: ${firstTaskHeight}px`);
          const label = (route === '/guia' ? 'es' : 'en') + '-' + viewport.width;
          if (output) {
            fs.mkdirSync(output, {recursive:true});
            await page.screenshot({path:path.join(output, 'guide-viewport-' + label + '.png')});
            await page.screenshot({path:path.join(output, 'guide-initial-' + label + '.png'), fullPage:true});
          }
          const legacyTask = groups.nth(1).locator('.r-guide-task').first();
          const legacyId = await legacyTask.getAttribute('id');
          await page.evaluate(id => {location.hash = id;}, legacyId);
          await page.waitForFunction(id => location.hash === '#' + id && document.getElementById(id)?.getBoundingClientRect().top < innerHeight, legacyId, {timeout:3000});
          assert.ok(await legacyTask.isVisible(), 'existing task hash links navigate directly to visible tasks');
          await page.reload({waitUntil:'networkidle'});
          await page.waitForFunction(id => location.hash === '#' + id && document.getElementById(id)?.getBoundingClientRect().top < innerHeight, legacyId, {timeout:3000});
          assert.ok(await legacyTask.isVisible(), 'direct loading an existing task hash keeps the task visible');
          const targetId = (await links.last().getAttribute('href')).slice(1);
          await links.last().focus();
          await page.keyboard.press('Enter');
          await page.waitForFunction(id => location.hash === '#' + id && document.getElementById(id)?.getBoundingClientRect().top < innerHeight, targetId, {timeout:3000});
          assert.equal(await guide.locator('.r-guide-task').evaluateAll(nodes => nodes.filter(node => node.checkVisibility()).length), 17, 'keyboard index navigation keeps every task visible');
          const images = guide.locator('.r-guide-figure img');
          const count = await images.count();
          assert.ok(count >= 1, 'guide includes reviewed Android screenshots');
          await images.evaluateAll(nodes => nodes.forEach(image => {image.loading='eager';}));
          await page.waitForFunction(() => [...document.querySelectorAll('.r-usage-guide .r-guide-figure img')].every(image => image.complete && image.naturalWidth > 0), null, {timeout:15000});
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'complete guide fits the viewport');
          const previews = await images.evaluateAll(nodes => nodes.map(image => {
            const imageBox=image.getBoundingClientRect();
            const task=image.closest('.r-guide-task');
            return {height:imageBox.height,width:imageBox.width,inTask:!!task,hasSteps:!!task?.querySelector('.r-guide-task-steps li')};
          }));
          assert.ok(previews.every(image => image.height > 0 && image.height <= 361), 'screenshots are compact previews, never full-phone-height rows');
          assert.ok(previews.every(image => image.inTask && image.hasSteps), 'each screenshot accompanies its task steps');
          const first = guide.locator('.r-guide-figure a').first();
          const original = await first.getAttribute('href');
          await first.click();
          const dialog = page.locator('.r-lightbox');
          await dialog.waitFor({state:'visible'});
          assert.equal(await dialog.locator('img').getAttribute('src'), new URL(original, base).href, 'zoom opens the full-size original');
          assert.ok((await dialog.locator('img').getAttribute('alt')).length > 0, 'zoom retains the accessible description');
          await page.keyboard.press('Escape');
          await dialog.waitFor({state:'hidden'});
          assert.equal(await first.evaluate(element => element === document.activeElement), true, 'closing zoom restores keyboard focus to the image');
          assert.deepEqual(errors, [], 'guide has no browser errors');
          assert.doesNotMatch(await guide.innerText(), /Pok[eé]mon Online|Reportes de errores|Reportar un error|Send an error report/iu, 'private modules are absent');
          const completeHeight = await guide.evaluate(element => element.getBoundingClientRect().height);
          if (output) await page.screenshot({path:path.join(output, 'guide-complete-' + label + '.png'), fullPage:true});
          results.push({language:route === '/guia'?'es':'en',viewport,groups:6,visibleTasks,accordionControls:0,images:count,firstTaskHeight,initialHeight,completeHeight,previewMaxHeight:Math.max(...previews.map(image=>image.height)),loaded:true,overflow:false,keyboard:true,zoom:true});
        } finally {await context.close();}
      }
    }
    console.log(JSON.stringify(results,null,2));
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
