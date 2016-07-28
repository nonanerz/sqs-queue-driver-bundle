<?php
/**
 * Created by mcfedr on 08/06/2016 23:37
 */

namespace Mcfedr\SqsQueueDriverBundle\Manager;

use Aws\Sqs\SqsClient;

trait SqsClientTrait
{
    /**
     * @var string
     */
    private $defaultUrl;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var SqsClient
     */
    private $sqs;

    private function setOptions(array $options)
    {
        $this->defaultUrl = $options['default_url'];
        $this->debug = $options['debug'];
        if (!$this->debug) {
            $sqsOptions = [
                'region' => $options['region'],
                'version' => '2012-11-05'
            ];
            if (array_key_exists('credentials', $options)) {
                $sqsOptions['credentials'] = $options['credentials'];
            }
            $this->sqs = new SqsClient($sqsOptions);
        }
    }
}