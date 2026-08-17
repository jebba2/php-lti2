<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpLti\Lti1p3Example\Render;

echo Render::page('php-lti example tool', <<<'HTML'
<p>This is a minimal LTI 1.3 tool built on <code>phplti/lti1p3</code>. It doesn't do anything
on its own — a platform launches it.</p>

<p>To try it out locally without a real Brightspace tenant, this example bundles a small
platform simulator. From the <code>example/</code> directory:</p>

<pre>composer install
php bin/setup.php
php -S localhost:8000 -t public                    # this tool
php -S localhost:8001 simulator/router.php         # the simulator, in another terminal</pre>

<p>Then open <a href="http://localhost:8001/">http://localhost:8001/</a> and click through —
that page plays the role of the platform (the thing that normally has a "launch tool" link
inside a real course).</p>

<p>See the main library's <a href="../README.md">README</a> for how to register this same tool
with a real D2L Brightspace instance instead.</p>
HTML);
