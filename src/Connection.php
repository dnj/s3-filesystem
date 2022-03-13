<?php

namespace dnj\Filesystem\S3;

use Aws\S3\S3Client;

/**
 * @phpstan-import-type ArgsType from Contracts\IConnection
 */
class Connection implements Contracts\IConnection
{
    protected S3Client $client;

    /**
     * @param ArgsType $args
     */
    public function __construct(protected array $args)
    {
        $this->client = new S3Client($args);
    }

    public function getS3Client(): S3Client
    {
        return $this->client;
    }

    /**
     * @return ArgsType
     */
    public function jsonSerialize(): array
    {
        return $this->args;
    }

    public function serialize(): string
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize(string $data)
    {
        /** @var ArgsType */
        $args = unserialize($data);
        $this->client = new S3Client($args);
    }
}
