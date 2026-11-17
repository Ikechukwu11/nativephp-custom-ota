<?php

namespace Ikechukwu11\NativephpCustomOta\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class BundleCommand extends Command
{
    protected $signature = 'ota:bundle
        {--output=storage/app/ota/release.zip : Output zip path}
        {--include-vendor : Include the vendor directory in the bundle}';

    protected $description = 'Package the Laravel app into an OTA release zip';

    public function handle(): int
    {
        $output = base_path($this->option('output'));
        $dir = dirname($output);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Unable to create zip at {$output}");

            return self::FAILURE;
        }

        $includeDirs = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes'];

        if ($this->option('include-vendor')) {
            $includeDirs[] = 'vendor';
        }

        $basePath = realpath(base_path());
        $fileCount = 0;

        foreach ($includeDirs as $folder) {
            $fullPath = $basePath.DIRECTORY_SEPARATOR.$folder;

            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getRealPath(), strlen($basePath) + 1));
                $zip->addFile($file->getRealPath(), $relativePath);
                $fileCount++;
            }
        }

        foreach (['artisan', 'composer.json', 'composer.lock'] as $rootFile) {
            $fullPath = $basePath.DIRECTORY_SEPARATOR.$rootFile;

            if (is_file($fullPath)) {
                $zip->addFile($fullPath, $rootFile);
                $fileCount++;
            }
        }

        $zip->close();

        $this->info("Bundled {$fileCount} files into {$output}");

        return self::SUCCESS;
    }
}

