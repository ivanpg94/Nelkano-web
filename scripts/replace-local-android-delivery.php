<?php
/** Replace the unpublished-to-production local beta after Ivan's mandatory-only packaging request. */
if (getenv('DRUPAL_DB_HOST') !== 'database' || getenv('NELKANO_REPLACE_LOCAL_ANDROID') !== '1') throw new RuntimeException('Explicit local replacement required');
$source='/opt/drupal/release-artifacts/Nelkano 3.0.0-beta.apk'; $uri='private://nelkano-releases/Nelkano 3.0.0-beta.apk';
$manifest=json_decode(file_get_contents('/opt/drupal/release-artifacts/manifest.json'),TRUE,flags:JSON_THROW_ON_ERROR); $expected=$manifest[basename($source)];
if (!hash_equals($expected,hash_file('sha256',$source))) throw new RuntimeException('Source integrity failure');
if (is_file($uri) && !in_array(hash_file('sha256',$uri),[$expected,'ca5313bf680a66dfb42790a4ee9faea3bd875f5b7aad815172b710961d861f4d','5a4650412c5a7cc9be2611c5580621f210229347546db0437625f0bef1ecfa9d'],TRUE)) throw new RuntimeException('Unexpected current artifact; refusing replacement');
if (!copy($source,$uri) || !hash_equals($expected,hash_file('sha256',$uri))) throw new RuntimeException('Copy integrity failure');
echo 'Local beta replaced with mandatory-only APK; version unchanged.' . PHP_EOL;
