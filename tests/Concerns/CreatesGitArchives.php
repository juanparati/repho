<?php

namespace Tests\Concerns;

use ZipArchive;

trait CreatesGitArchives
{
    /**
     * Build an in-memory zip archive the way Git hosts deliver them: an
     * optional top-level folder wrapping every file.
     *
     * @param  array<string, string>  $files  path => contents
     */
    protected function makeZipArchive(array $files, string $prefix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'repho-test-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($prefix.$name, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * List the entries of a gzipped tarball as path => contents.
     *
     * @return array<string, string>
     */
    protected function readTarball(string $tgz): array
    {
        $tar = gzdecode($tgz);
        $this->assertNotFalse($tar, 'Tarball is not valid gzip.');

        $entries = [];
        $offset = 0;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);

            if (trim($header, "\0") === '') {
                break;
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $size = octdec(trim(substr($header, 124, 12)));

            $fullName = $prefix !== '' ? "{$prefix}/{$name}" : $name;
            $entries[$fullName] = substr($tar, $offset + 512, $size);

            $offset += 512 + (int) (ceil($size / 512) * 512);
        }

        return $entries;
    }
}
