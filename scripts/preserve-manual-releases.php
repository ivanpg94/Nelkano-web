<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/release-catalog-state.php';
nelkanoPreserveManualReleases((string) getenv('NELKANO_RELEASE_CATALOG_ACTION'));
