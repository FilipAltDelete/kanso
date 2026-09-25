<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Storage;

/**
 * The installation's object storage (S3-compatible; MinIO locally). Nothing
 * is kept on a container's disk: what a job produces is written here, and a
 * browser fetches it straight from the store through a short-lived signed
 * URL, never through a PHP process (as in Pimsen).
 */
interface ObjectStorageInterface
{
    /** @param resource|string $contents */
    public function write(string $key, mixed $contents, ?string $contentType = null): void;

    /** @return resource */
    public function readStream(string $key);

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /**
     * A URL a browser may GET the object from until it expires.
     *
     * @param string|null $filename the name the browser shows and saves it as
     */
    public function presignDownload(string $key, int $ttlSeconds, ?string $filename = null): string;
}
