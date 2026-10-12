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
    const sdkPath = process.env.NELKANO_SDK_SOURCE || path.resolve('../Nelkano-emulator/src/android-new/sdk/tooling/package.mjs');
    const sdk = await import(pathToFileURL(sdkPath).href);
    const source = path.join(temporary, 'module'); await fs.mkdir(path.join(source, 'src'), {recursive: true});
    const manifest = {formatVersion: 1, id: 'example.webcatalog', name: 'SDK compatibility test', version: '1.0.0',
      sdkVersion: '0.2.0', platforms: ['android', 'jvm-desktop'], entry: 'src/main.js', bundledByDefault: false, dependencies: [],
      capabilities: ['library.read'], files: ['src/main.js']};
    await fs.writeFile(path.join(source, 'manifest.json'), JSON.stringify(manifest));
    await fs.writeFile(path.join(source, 'src/main.js'), 'export function createModule() { return {}; }');
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
    console.log('PASS: package built and verified by the real SDK is accepted with identical digest/author by Drupal.');
  } finally {
    execFileSync('docker', ['compose', 'exec', '-T', 'drupal', 'php', '-r', 'unlink($argv[1]);', remote]);
    const absolute = path.resolve(temporary);
    if (!absolute.startsWith(path.resolve(os.tmpdir()) + path.sep + 'nelkano-catalog-sdk-')) throw new Error('Unsafe cleanup path');
    await fs.rm(absolute, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });