/* Render demo covers from the site's existing vector icons; generated PNGs are disposable. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const escape = value => value.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
(async () => {
  const definitions = JSON.parse(fs.readFileSync(path.join(__dirname, 'module-demo.json'), 'utf8'));
  const output = path.resolve('outputs/module-demo');
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless:true, channel:process.env.NELKANO_TEST_BROWSER || 'chrome'});
  try {
    const page = await browser.newPage({viewport:{width:1280,height:720},deviceScaleFactor:1});
    for (const module of definitions) {
      if (module.image === false) continue;
      const icon = fs.readFileSync(path.resolve('public_html/modules/custom/nelkano_home/assets/figma', module.icon + '.svg'), 'utf8').replaceAll('#64748b','currentColor');
      const label = module.platforms.length === 2 ? 'Android · Windows · Linux' : module.platforms[0] === 'android' ? 'Android' : 'Windows · Linux';
      await page.setContent(`<!doctype html><html lang="es"><meta charset="utf-8"><style>
        *{box-sizing:border-box}body{margin:0;background:${module.background};color:#17162a;font-family:Arial,sans-serif}
        .cover{position:relative;width:1280px;height:720px;padding:78px;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between}
        .orb{position:absolute;width:520px;height:520px;border-radius:50%;background:${module.accent};opacity:.08;right:-120px;top:-150px}
        .eyebrow{color:${module.accent};font-size:24px;font-weight:700;letter-spacing:4px}.row{display:flex;align-items:center;gap:42px}
        .icon{width:180px;height:180px;flex:0 0 180px;display:grid;place-items:center;border-radius:40px;background:#ffffffb3;color:${module.accent};border:1px solid #ffffff}
        svg{width:110px;height:110px}h1{font-size:66px;line-height:1.12;letter-spacing:-2px;margin:0;max-width:880px}
        .bottom{display:flex;justify-content:space-between;align-items:center;color:${module.accent};font-size:24px}
        .badge{padding:14px 24px;border:1px solid ${module.accent}30;border-radius:100px;background:#ffffff99;font-size:20px}
      </style><div class="cover"><div class="orb"></div><div class="eyebrow">NELKANO · SDK</div><div class="row"><div class="icon">${icon}</div><h1>${escape(module.name)}</h1></div><div class="bottom"><span>${label}</span><span class="badge">Módulo de prueba</span></div></div></html>`);
      await page.locator('.cover').screenshot({path:path.join(output,module.slug+'.png')});
    }
    console.log('Rendered 14 demo covers in '+output);
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});