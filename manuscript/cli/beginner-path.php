<?php
/*
 * Sends today's beginner path emails (lib/path.php). The site also does this with the first visit of the day, so a
 * cron job is optional; with one, emails go out at a set time. In hPanel → Advanced → Cron Jobs, once a day:
 *   php /home/USER/domains/magickmuse.com/public_html/manuscript/cli/beginner-path.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/render.php';
require __DIR__ . '/../lib/mail.php';
require __DIR__ . '/../lib/path.php';
@touch(config('data_dir') . '/last-path-run');
echo 'Sent ' . run_beginner_path(1000) . " beginner path emails.\n";
