<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Storage;

use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use Kanso\Core\Internal\Domain\Storage\ObjectStorageInterface;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;

/**
 * Any S3-compatible store: AWS, MinIO, whatever the customer runs. Flysystem
 * does the reading and writing; the client does the presigning, which
 * Flysystem has no interface for (same as Pimsen).
 *
 * Two clients, on purpose. The API reaches the store on its internal address
 * (`minio:9000`), but a presigned URL is followed by a *browser*, which cannot
 * resolve that. SigV4 signs the host header, so the URL cannot be rewritten
 * afterwards: `$presigner` signs for the address the browser uses
 * (`S3_PUBLIC_ENDPOINT`), and is the same client where the two match.
 */
final class S3ObjectStorage implements ObjectStorageInterface
{
    private readonly Filesystem $filesystem;

    public function __construct(
        S3Client $client,
        private readonly S3Client $presigner,
        private readonly string $bucket,
        private readonly ClockInterface $clock,
    ) {
        $this->filesystem = new Filesystem(new AsyncAwsS3Adapter($client, $bucket));
    }

    public function write(string $key, mixed $contents, ?string $contentType = null): void
    {
        $config = null === $contentType ? [] : ['ContentType' => $contentType];

        if (\is_resource($contents)) {
            $this->filesystem->writeStream($key, $contents, $config);

            return;
        }

        $this->filesystem->write($key, (string) $contents, $config);
    }

    public function readStream(string $key)
    {
        return $this->filesystem->readStream($key);
    }

    public function exists(string $key): bool
    {
        return $this->filesystem->fileExists($key);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    public function presignDownload(string $key, int $ttlSeconds, ?string $filename = null): string
    {
        $input = ['Bucket' => $this->bucket, 'Key' => $key];
        if (null !== $filename) {
            // Inline, so a PDF opens in the browser's viewer, ready to print.
            $input['ResponseContentDisposition'] = \sprintf('inline; filename="%s"', addcslashes($filename, '"\\'));
        }

        return $this->presigner->presign(new GetObjectRequest($input), $this->clock->now()->modify(\sprintf('+%d seconds', $ttlSeconds)));
    }
}
