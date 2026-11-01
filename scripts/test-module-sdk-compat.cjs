const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const {pathToFileURL} = require('node:url');
const {execFileSync} = require('node:child_process');
const {generateKeyPairSync} = require('node:crypto');
const assert = require('node:assert/strict');

(async () => {
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'nelkano-catalog-sdk-'));
  const remote = '/tmp/nelkano-sdk-fixture-' + path.basename(temporary) + '.nelkmod';
  try {
    const sdkPath = process.env.NELKANO_SDK_SOURCE || path.resolve('../Nelkano-emulator/src/nelkano/sdk/tooling/package.mjs');
    const sdk = await import(pathToFileURL(sdkPath).href);
    const sourceContract = await fs.readFile(sdkPath, 'utf8');
    const capabilityMatch = sourceContract.match(/const knownCapabilities = new Set\((\[[^;]+\])\);/);
    assert.ok(capabilityMatch, 'SDK public capability contract is available');
    const publicCapabilities = JSON.parse(capabilityMatch[1]);
    const verifier = await fs.readFile('public_html/modules/custom/nelkano_modules/src/Service/PackageVerifier.php', 'utf8');
    const publicSection = verifier.match(/private const CAPABILITIES = \[([\s\S]*?)\];/)[1];
    const webCapabilities = [...publicSection.matchAll(/'([^']+)'/g)].map(match => match[1]);
    assert.deepEqual(webCapabilities.sort(), [...publicCapabilities].sort(), 'web and SDK public capability lists must remain identical');
    const source = path.join(temporary, 'module'); await fs.mkdir(path.join(source, 'src'), {recursive: true});
    const manifest = {formatVersion: 1, id: 'example.webcatalog', name: 'SDK compatibility test', version: '1.0.0',
      sdkVersion: '0.2.0', platforms: ['android', 'jvm-desktop'], entry: 'src/main.js', bundledByDefault: false, dependencies: [],
      capabilities: publicCapabilities,
      files: ['src/main.js', 'components/test/android.dex', 'components/test/jvm-desktop.jar'],
      components: [
        {id: 'test', platform: 'android', path: 'components/test/android.dex', entryPoint: 'example.Test', apiVersion: 1},
        {id: 'test', platform: 'jvm-desktop', path: 'components/test/jvm-desktop.jar', entryPoint: 'example.Test', apiVersion: 1},
      ]};
    await fs.writeFile(path.join(source, 'manifest.json'), JSON.stringify(manifest));
    await fs.writeFile(path.join(source, 'src/main.js'), 'export function createModule() { return {}; }');
    await fs.mkdir(path.join(source, 'components/test'), {recursive: true});
    await fs.writeFile(path.join(source, 'components/test/android.dex'), Buffer.from('dex\n035\0synthetic fixture'));
    await fs.writeFile(path.join(source, 'components/test/jvm-desktop.jar'), Buffer.from('synthetic fixture'));
    const key = generateKeyPairSync('ed25519');
    const bytes = await sdk.buildPackage(source, key.privateKey.export({type: 'pkcs8', format: 'pem'}));
    const expected = sdk.validatePackage(bytes);
    const archive = path.join(temporary, 'sdk.nelkmod'); await fs.writeFile(archive, bytes);
    execFileSync('docker', ['cp', archive, 'nelkano-drupal:' + remote]);
    const actual = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'drupal', 'php',
      'web/modules/custom/nelkano_modules/tests/verify-sdk-fixture.php', remote], {encoding: 'utf8'}));
    assert.equal(actual.sha256, expected.archiveSha256);
    assert.equal(actual.author, expected.authorFingerprint);
    assert.equal(actual.id, expected.id); assert.equal(actual.version, expected.version);
    assert.deepEqual(actual.capabilities, publicCapabilities);
    console.log('PASS: all ' + publicCapabilities.length + ' public SDK capabilities and native components accepted for a new author with identical digest; catalog and SDK lists match.');
  } finally {
    execFileSync('docker', ['compose', 'exec', '-T', 'drupal', 'php', '-r', 'unlink($argv[1]);', remote]);
    const absolute = path.resolve(temporary);
    if (!absolute.startsWith(path.resolve(os.tmpdir()) + path.sep + 'nelkano-catalog-sdk-')) throw new Error('Unsafe cleanup path');
    await fs.rm(absolute, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });