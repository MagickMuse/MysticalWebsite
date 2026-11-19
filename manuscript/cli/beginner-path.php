<?php
/*
 * Sends the beginner path emails that are due: each member's next day at 11:11 in their own time zone (lib/path.php).
 * In hPanel → Advanced → Cron Jobs, add a custom job with minute 11,26,41,56 and every hour, day, month and weekday:
 *   php /home/USER/domains/magickmuse.com/public_html/manuscript/cli/beginner-path.php
 * Those four minutes catch 11:11 in every time zone, including half- and quarter-hour ones.
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
