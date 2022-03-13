<?php

namespace dnj\Filesystem\S3;

use Aws\S3\Exception\S3Exception;
use dnj\Filesystem\Contracts\IDirectory;
use dnj\Filesystem\Exceptions\IOException;
use dnj\Filesystem\Exceptions\NotFoundException;
use dnj\Filesystem\S3\Contracts\IConnection;
use dnj\Filesystem\S3\Exceptions\InvalidAccessKeyException;

trait NodeTrait
{
    protected string $bucket;

    protected IConnection $connection;

    public function __construct(string $path, IConnection $connection, string $bucket)
    {
        parent::__construct($path);
        $this->connection = $connection;
        $this->bucket = $bucket;
    }

    public function getConnection(): IConnection
    {
        return $this->connection;
    }

    public function getBucketName(): string
    {
        return $this->bucket;
    }

    public function getRelativePath(IDirectory $base): string
    {
        if (!$base instanceof Directory) {
            throw new \InvalidArgumentException('base is not a '.Directory::class.' directory!');
        }

        return parent::getRelativePath($base);
    }

    public function getDirectory(): Directory
    {
        return new Directory($this->directory, $this->connection, $this->bucket);
    }

    /**
     * @return array{connection:IConnection,path:string,bucket:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->getPath(),
            'connection' => $this->connection,
            'bucket' => $this->bucket,
        ];
    }

    public function serialize(): string
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize($data)
    {
        /** @var array{connection:IConnection,path:string,bucket:string} $data */
        $data = unserialize($data);
        $this->bucket = $data['bucket'];
        $this->connection = $data['connection'];
        $this->directory = dirname($data['path']);
        $this->basename = basename($data['path']);
    }

    protected function fromS3Exception(S3Exception $e): \Exception
    {
        $class = IOException::class;
        if ('NoSuchKey' == $e->getAwsErrorCode()) {
            $class = NotFoundException::class;
        } elseif ('InvalidAccessKeyId' == $e->getAwsErrorCode()) {
            $class = InvalidAccessKeyException::class;
        }

        return new $class($this, $e->getAwsErrorMessage() ?: $e->getMessage(), 0, $e);
    }

    protected function normalizedPath(?string $path = null): string
    {
        $path = $path ?? $this->getPath();
        while ('.' == substr($path, 0, 1) and '.' !== substr($path, 1, 1)) {
            $path = ltrim(substr($path, 1), '/');
        }

        return ltrim($path, '/');
    }
}
