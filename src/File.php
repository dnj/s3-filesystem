<?php

namespace dnj\Filesystem\S3;

use Aws\S3\Exception\S3Exception;
use dnj\Filesystem\Contracts\IFile;
use dnj\Filesystem\File as FileAbstract;
use dnj\Filesystem\Tmp;

class File extends FileAbstract implements \JsonSerializable
{
    use NodeTrait;

    public function write(string $data): void
    {
        try {
            $result = $this->connection->getS3Client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
                'Body' => $data,
            ]);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function read(int $length = 0): string
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
            ];
            if ($length) {
                $params['Range'] = 'bytes=0-'.$length;
            }
            $result = $this->connection->getS3Client()->getObject($params);

            return strval($result['Body']);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function size(): int
    {
        try {
            $result = $this->connection->getS3Client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
            ]);

            return intval($result['ContentLength']);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function rename(string $newName): void
    {
        try {
            $result = $this->connection->getS3Client()->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizedPath($this->directory.'/'.$newName),
                'CopySource' => $this->bucket.'/'.$this->normalizedPath(),
            ]);

            $this->delete();

            $this->basename = $newName;
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function move(IFile $dest): void
    {
        if ($dest instanceof self) {
            $this->rename($dest->basename);
        }
        $this->copyTo($dest);
        $this->delete();
    }

    public function delete(): void
    {
        try {
            $result = $this->connection->getS3Client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
            ]);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function copyTo(IFile $dest): void
    {
        try {
            $localFile = Tmp\File::insureLocal($dest);
            $this->connection->getS3Client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
                'SaveAs' => $localFile->getPath(),
            ]);
            if ($localFile != $dest) {
                $localFile->copyTo($dest);
            }
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function copyFrom(IFile $source): void
    {
        try {
            $result = $this->connection->getS3Client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->getPath(),
                'SourceFile' => Tmp\File::insureLocal($source)->getPath(),
            ]);
        } catch (S3Exception $e) {
            throw $this->fromS3Exception($e);
        }
    }

    public function exists(): bool
    {
        try {
            $result = $this->connection->getS3Client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizedPath(),
            ]);

            return isset($result['ContentLength']);
        } catch (S3Exception $e) {
            if ('NotFound' == $e->getAwsErrorCode()) {
                return false;
            }
            throw $this->fromS3Exception($e);
        }
    }
}
