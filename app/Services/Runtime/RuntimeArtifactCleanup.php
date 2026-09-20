<?php

namespace App\Services\Runtime;

use Illuminate\Support\Facades\File;

final class RuntimeArtifactCleanup
{
    public function downloads(): void
    {
        $root = realpath(storage_path('app/private/downloads'));
        if ($root === false) {
            return;
        }
        $cutoff = now()->subHours(2)->timestamp;
        foreach (array_slice(File::directories($root), 0, 25) as $directory) {
            $path = realpath($directory);
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                File::deleteDirectory($path);
            }
        }
    }
}
