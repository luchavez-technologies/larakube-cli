<?php

namespace App\Traits\CliTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ZipArchive;

/**
 * Streaming, lock-tolerant downloads for the native Windows installers, where
 * there is no bash/curl/tar to run the Darwin/Linux one-liners against.
 */
trait DownloadsReleaseArtifacts
{
    /**
     * Download a URL straight to a file path, for tools distributed as a
     * bare binary (kubectl.exe) rather than an archive. Uses Laravel's HTTP
     * client instead of shelling to curl, since a native Windows host has no
     * bash to run this file's other installers' curl/tar one-liners against.
     *
     * Streams straight to disk via Guzzle's `sink` option — never buffers
     * the whole response body in PHP memory first. kubectl/OpenTofu/the
     * Google Cloud SDK zip are all 50-150MB; the previous `$response->body()`
     * + `file_put_contents()` approach held the entire file in a PHP string
     * (effectively twice, once in Guzzle's own buffer and once in that
     * string) before ever writing a byte, which blew past PHP's default
     * 128M memory_limit outright — confirmed live (2026-10-29): every
     * Windows tool install past ~75MB failed with "Allowed memory size of
     * 134217728 bytes exhausted."
     *
     * Downloads to a sibling temp file first, then rename()s onto $target —
     * $target is frequently a binary already in use (an existing
     * kubectl.exe being updated in place). Streaming straight onto that live
     * path left it open/partially-written for the whole download, and
     * confirmed live (2026-10-30): a transient lock on it (AV scanning the
     * freshly-written exe, a stray process still holding it) could make
     * this — or the isInstalled() check immediately after — fail even
     * though the file was actually fine moments later. rename() on the same
     * filesystem is atomic and near-instant, so there's effectively no
     * window left for that race. On Windows, rename() refuses to replace an
     * existing destination outright, so $target is removed first; if THAT
     * unlink hits the exact same transient lock, one retry after a brief
     * pause covers it before giving up.
     */
    protected function httpDownloadToFile(string $url, string $target): bool
    {
        $tmp = $target.'.download';
        @unlink($tmp);

        // retry(): GitHub's (and dl.k8s.io's) release CDN occasionally resets
        // the connection mid-download — confirmed live (2026-10-30): "The
        // request was aborted: The connection was closed unexpectedly" on a
        // real Windows machine — almost always transient.
        $response = Http::timeout(120)->retry(3, 2000)->sink($tmp)->get($url);
        if (! $response->successful()) {
            @unlink($tmp);

            return false;
        }

        @chmod($tmp, 0755);

        return self::replaceFile($tmp, $target);
    }

    /**
     * Download a zip and extract one named member (matched by basename,
     * regardless of which folder it's nested under inside the archive) to a
     * target path. Covers k9s/OpenTofu's Windows release zips.
     *
     * Extracts to a sibling temp file and rename()s onto $target, same as
     * httpDownloadToFile() — $target is the same kind of live, possibly
     * already-running binary (k9s.exe/tofu.exe being updated in place), so
     * it's exposed to the identical transient-lock race.
     */
    protected function httpDownloadZipMember(string $url, string $target, string $memberBasename): bool
    {
        $tmpTarget = $target.'.download';
        $tmpZip = tempnam(sys_get_temp_dir(), 'larakube-zip-');
        if ($tmpZip === false) {
            return false;
        }

        // Streams straight to disk (see httpDownloadToFile()'s docblock) so
        // the download itself never buffers in memory. retry(): see
        // httpDownloadToFile()'s docblock on the connection-reset risk.
        $response = Http::timeout(120)->retry(3, 2000)->sink($tmpZip)->get($url);
        if (! $response->successful()) {
            @unlink($tmpZip);

            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);

            return false;
        }

        $extracted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && basename($name) === $memberBasename) {
                // getFromIndex() decompresses the whole member into a PHP
                // string first — fine for a small config file, but a real
                // binary (tofu.exe/k9s.exe, 100MB+ decompressed) blew past
                // PHP's default 128M memory_limit here too, confirmed live
                // (2026-10-30) even after the download itself was fixed to
                // stream. getStream() instead hands back a read-only
                // resource ZipArchive fills on demand as it's read, so
                // stream_copy_to_stream() moves it to disk in chunks and
                // never holds the whole decompressed file in memory at once.
                $source = $zip->getStream($name);
                $destination = $source !== false ? fopen($tmpTarget, 'wb') : false;

                if ($source !== false && $destination !== false) {
                    $extracted = stream_copy_to_stream($source, $destination) !== false;
                    fclose($destination);
                    @chmod($tmpTarget, 0755);
                }

                if (is_resource($source)) {
                    fclose($source);
                }

                break;
            }
        }

        $zip->close();
        @unlink($tmpZip);

        if (! $extracted) {
            @unlink($tmpTarget);

            return false;
        }

        return self::replaceFile($tmpTarget, $target);
    }

    /**
     * Download a zip and extract its entire contents into a directory —
     * Google Cloud SDK's Windows zip ships a whole `google-cloud-sdk/` tree,
     * not a single binary, mirroring what `tar -xz` does for the Darwin/Linux
     * tarball install above.
     */
    protected function httpDownloadZipExtractAll(string $url, string $destDir): bool
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'larakube-zip-');
        if ($tmpZip === false) {
            return false;
        }

        // Streams straight to disk (see httpDownloadToFile()'s docblock) —
        // the Google Cloud SDK's Windows zip alone is well over 100MB.
        // retry(): see httpDownloadToFile()'s docblock on the connection-reset risk.
        $response = Http::timeout(300)->retry(3, 2000)->sink($tmpZip)->get($url);
        if (! $response->successful()) {
            @unlink($tmpZip);

            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);

            return false;
        }

        @mkdir($destDir, 0755, true);
        $extracted = $zip->extractTo($destDir);
        $zip->close();
        @unlink($tmpZip);

        return $extracted;
    }

    /**
     * The last N bytes of a file without reading the whole thing into
     * memory first — an MSI verbose log can run to several MB, and this
     * codebase already hit PHP's 128M memory_limit once this session by
     * buffering a whole file before trimming it.
     */
    protected static function fileTail(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $size = filesize($path) ?: 0;
        fseek($handle, -min($bytes, $size), SEEK_END);
        $tail = stream_get_contents($handle);
        fclose($handle);

        return trim((string) $tail);
    }

    /**
     * Moves a completed download onto its final path, replacing whatever
     * (if anything) is there — retrying once after a short pause if the
     * destination is transiently locked (see httpDownloadToFile()'s
     * docblock). Shared by every installer that writes a single file.
     */
    protected static function replaceFile(string $tmp, string $target): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            @unlink($target);
            if (@rename($tmp, $target)) {
                return true;
            }

            Sleep::usleep(200_000);
        }

        @unlink($tmp);

        return false;
    }
}
