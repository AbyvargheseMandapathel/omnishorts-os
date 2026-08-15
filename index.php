<?php

/**
 * Proxy entry point for shared hosting (Hostinger / cPanel).
 *
 * When the domain's document root is public_html/ (not public_html/public/),
 * this file forwards every request to public/index.php so Laravel boots
 * normally — no .htaccess rewrite needed.
 */

require __DIR__.'/public/index.php';
