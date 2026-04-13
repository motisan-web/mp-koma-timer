<?php
/**
 * Embed entry point
 * Suppresses site header/footer — for iframe embedding in moti tools etc.
 */
define('EMBED_MODE', true);
include __DIR__ . '/index.php';
