<?php
/** Reuse the authenticated legacy/controller flow, with a real larger image context. */
putenv('NELKANO_ASSISTANT_CONTEXT_TEST=1');
require __DIR__.'/api-actions-smoke.php';
