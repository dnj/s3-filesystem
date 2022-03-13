<?php

namespace dnj\Filesystem\S3\Contracts;

use Aws\S3\S3Client;

/**
 * @phpstan-type ArgsType array{'credentials':array{'key':string,'secret':string},'endpoint'?:string,'use_path_style_endpoint'?:bool,'region'?:string}
 */
interface IConnection extends \Serializable, \JsonSerializable
{
    /**
     * @param ArgsType $args
     */
    public function __construct(array $args);

    public function getS3Client(): S3Client;
}
