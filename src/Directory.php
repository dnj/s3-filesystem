<?php

namespace dnj\Filesystem\S3;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use dnj\Filesystem\Contracts\IDirectory;
use dnj\Filesystem\Directory as DirectoryAbstract;
use dnj\Filesystem\Exceptions\IOException;

class Directory extends DirectoryAbstract implements \JsonSerializable
{
    use NodeTrait;

    /**
     * @return \Generator<File>
     */
    public function files(bool $recursively): \Generator
    {
        $params = [];
        if (!$recursively) {
            $params['Delimiter'] = '/';
        }

        return $this->fetcher($params, function (Result $result) {
            /** @var array<array{'Key':string}> */
            $contents = $result->get('Contents') ?? [];

            return $contents ? array_map(
                fn (array $item): File => new File($item['Key'], $this->connection, $this->bucket),
                array_filter(
                    $contents,
                    fn (array $item) => ($item['Key'] and '/' !== substr($item['Key'], -1))
                )
            ) : [];
        });
    }

    /**
     * @return \Generator<self>
     */
    public function directories(bool $recursively): \Generator
    {
        $params = [];
        if (!$recursively) {
            $params['Delimiter'] = '/';
        }

        return $this->fetcher($params, function (Result $result) use ($recursively): array {
            if (!$recursively) {
                /** @var array<array{'Prefix':string}> */
                $commonPrefixes = $result->get('CommonPrefixes') ?? [];

                return array_map(
                    fn (array $commonPrefixes): Directory => new Directory($commonPrefixes['Prefix'], $this->connection, $this->bucket),
                    $commonPrefixes
                );
            }

            /** @var array<array{'Key':string}> */
            $contents = $result->get('Contents') ?? [];
            if (!$contents) {
                return [];
            }

            $paths = [];
            $normalizedPath = rtrim($this->normalizedPath(), '/').'/';
            foreach ($contents as $content) {
                $parts = explode('/', ($content['Key']));
                $count = count($parts) - 1; // don't care about last part, it may be empty or file name

                $path = '';
                for ($x = 0; $x < $count; ++$x) {
                    $path .= $parts[$x].'/';
                    if ($path == $normalizedPath) {
                        continue;
                    }
                    if (!in_array($path, $paths)) {
                        $paths[] = $path;
                    }
                }
            }

            return array_map(
                fn (string $path) => new Directory($path, $this->connection, $this->bucket),
                $paths
            );
        });
    }

    /**
     * @return \Generator<self|File>
     */
    public function items(bool $recursively): \Generator
    {
        $params = [];
        if (!$recursively) {
            $params['Delimiter'] = '/';
        }

        return $this->fetcher($params, function (Result $result) {
            /** @var array<array{Key:string}> */
            $contents = $result->get('Contents') ?? [];

            return array_map(
                fn (array $item) => ($item['Key'] and '/' !== substr($item['Key'], -1)) ?
                    new File($item['Key'], $this->connection, $this->bucket) :
                    new Directory($item['Key'], $this->connection, $this->bucket),
                $contents
            );
        });
    }

    public function make(bool $recursively = true): void
    {
        try {
            $s3Client = $this->connection->getS3Client();
            $s3Client->registerStreamWrapper();
            @mkdir($this->getStreamLink(), 755, $recursively);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function size(bool $recursively): int
    {
        $params = [];
        if (!$recursively) {
            $params['Delimiter'] = '/';
        }
        $iterator = $this->fetcher($params, function (Result $result) {
            /** @var array<array{'Key':string,'Size'?:string|int}> */
            $contents = $result->get('Contents') ?? [];

            return array_map(
                // do not include size of directories (if exists)
                fn (array $content) => ($content['Key'] and '/' !== substr($content['Key'], -1)) ? intval($content['Size'] ?? 0) : 0,
                $contents
            );
        });

        return array_sum(iterator_to_array($iterator)); // sum of sums
    }

    public function rename(string $newName): void
    {
        $this->move(new self($this->directory.'/'.$newName, $this->connection, $this->bucket));
    }

    public function move(IDirectory $dest): void
    {
        if (!$dest instanceof self) {
            throw new IOException($dest, 'unsupported dest');
        }

        if ($this->getPath() == $dest->getPath()) {
            return;
        }
        try {
            foreach ($this->files(true) as $item) {
                $path = $item->getRelativePath($this);
                $item->copyTo($dest->file($item->getRelativePath($this)));
                $item->delete();
            }
            $this->delete();
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }

        $this->directory = $dest->directory;
        $this->basename = $dest->basename;
    }

    public function file(string $name): File
    {
        return new File(
            $this->getPath().'/'.$name,
            $this->connection,
            $this->bucket
        );
    }

    public function directory(string $name): self
    {
        return new self(
            $this->getPath().'/'.$name,
            $this->connection,
            $this->bucket
        );
    }

    public function exists(): bool
    {
        try {
            $result = $this->connection->getS3Client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => rtrim($this->normalizedPath(), '/').'/',
            ]);

            return true;
        } catch (S3Exception $e) {
            if ('NotFound' == $e->getAwsErrorCode() or 'NoSuchKey' == $e->getAwsErrorCode()) {
                return false;
            }
            throw $this->fromS3Exception($e);
        }
    }

    public function delete(): void
    {
        try {
            $this->connection->getS3Client()->deleteMatchingObjects(
                $this->bucket,
                rtrim($this->normalizedPath(), '/')
            );
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    protected function getStreamLink(): string
    {
        return 's3://'.$this->getBucketName().'/'.$this->normalizedPath();
    }

    /**
     * @template T
     *
     * @param array{'Bucket'?:string,'Prefix'?:string,'FetchOwner'?:bool,'MaxKeys'?:int} $params
     * @param callable(\Aws\Result<string,mixed> $result):array<T> $proccessor
     *
     * @return \Generator<T>
     */
    private function fetcher(array $params, callable $proccessor): \Generator
    {
        $params = array_merge([
            'Bucket' => $this->bucket,
            'Prefix' => rtrim($this->normalizedPath(), '/').'/',
            'FetchOwner' => false,
            // 'MaxKeys' => 1, // the maximum number of objects that will we got in one request
        ], $params);

        $nextContinuationToken = null;
        do {
            if ($nextContinuationToken) {
                $params['ContinuationToken'] = $nextContinuationToken;
            } else {
                unset($params['ContinuationToken']);
            }
            try {
                $result = $this->connection->getS3Client()->listObjectsV2($params);
                $nextContinuationToken = $result->get('NextContinuationToken');

                /** @var T[] $items */
                $items = call_user_func($proccessor, $result);
                foreach ($items as $item) {
                    yield $item;
                }
            } catch (S3Exception $e) {
                throw $this->fromS3Exception($e);
            }
        } while ($nextContinuationToken);
    }
}
