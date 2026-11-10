<?php

// Run in io-app: php /benchmark/filesystem.php. No writes to source or database.
$files = [];
foreach (['app', 'config', 'routes', 'bootstrap'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/windows/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/bootstrap/cache/')) {
            $files[] = substr($file->getPathname(), strlen('/windows/'));
        }
    }
}
sort($files);
$results = ['windows' => [], 'linux' => []];
$digests = [];
for ($round = 0; $round < 10; $round++) {
    foreach ($round % 2 ? ['linux', 'windows'] : ['windows', 'linux'] as $mode) {
        $root = $mode === 'windows' ? '/windows/' : '/var/www/html/';
        $hash = hash_init('sha256');
        clearstatcache();
        $start = hrtime(true);
        foreach ($files as $file) {
            if (! is_file($root.$file)) {
                throw new RuntimeException('Missing source file: '.$file);
            }
            hash_update($hash, file_get_contents($root.$file));
        }
        $duration = (hrtime(true) - $start) / 1e6;
        $digests[$mode] = hash_final($hash);
        if ($round > 0) {
            $results[$mode][] = round($duration, 3);
        }
    }
}
if ($digests['windows'] !== $digests['linux']) {
    throw new RuntimeException('Source copies differ');
}
echo json_encode(['files' => count($files), 'sha256' => $digests['windows'], 'samples_ms' => $results], JSON_PRETTY_PRINT).PHP_EOL;
