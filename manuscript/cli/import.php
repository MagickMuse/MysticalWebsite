<?php
/*
 * Moves grimoires from Notion into the wiki.
 *
 *   php manuscript/cli/import.php                    import every grimoire listed in the config
 *   php manuscript/cli/import.php correspondence     import just one (by its config slug)
 *   php manuscript/cli/import.php --prune            also remove entries that were deleted in Notion
 *
 * Safe to re-run any time: only pages edited in Notion since the last run are downloaded again.
 * Hostinger: run it over SSH, or add it as a Cron Job (e.g. weekly) in hPanel.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/notion.php';

set_time_limit(0);
$args = array_slice($argv, 1);
$prune = in_array('--prune', $args, true);
$only = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$grimoires = config('grimoires', []);
if ($only) {
    $unknown = array_diff($only, array_keys($grimoires));
    if ($unknown) { fwrite(STDERR, 'Not in config grimoires: ' . implode(', ', $unknown) . "\n"); exit(1); }
    $grimoires = array_intersect_key($grimoires, array_flip($only));
}
if (!$grimoires) { fwrite(STDERR, "No grimoires configured.\n"); exit(1); }

$lock = fopen(config('data_dir') . '/import.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Another import is already running.\n"); exit(1); }

$start = microtime(true);
$importer = new NotionImporter(fn($m) => print(date('H:i:s ') . $m . "\n"));
foreach ($grimoires as $slug => $id) {
    try {
        $importer->importGrimoire($slug, $id, $prune);
    } catch (Throwable $ex) {
        echo '! ' . $slug . ': ' . $ex->getMessage() . "\n";
    }
}
$s = $importer->stats();
printf("Done in %.1f min: %d pages fetched, %d unchanged, %d images, %d errors.\n",
    (microtime(true) - $start) / 60, $s['fetched'], $s['skipped'], $s['images'], $s['errors']);
