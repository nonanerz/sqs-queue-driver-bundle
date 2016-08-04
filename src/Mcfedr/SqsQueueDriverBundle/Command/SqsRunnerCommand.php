<?php
/**
 * Created by mcfedr on 05/03/2016 15:45
 */

namespace Mcfedr\SqsQueueDriverBundle\Command;

use Mcfedr\QueueManagerBundle\Command\RunnerCommand;
use Mcfedr\QueueManagerBundle\Exception\UnexpectedJobDataException;
use Mcfedr\QueueManagerBundle\Manager\QueueManager;
use Mcfedr\SqsQueueDriverBundle\Manager\SqsClientTrait;
use Mcfedr\SqsQueueDriverBundle\Queue\SqsJob;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class SqsRunnerCommand extends RunnerCommand
{
    use SqsClientTrait;

    /**
     * @var int
     */
    private $visibilityTimeout = 30;

    /**
     * @var int
     */
    private $batchSize = 10;

    /**
     * @var string[]
     */
    private $urls;

    public function __construct($name, array $options, QueueManager $queueManager)
    {
        parent::__construct($name, $options, $queueManager);
        $this->setOptions($options);
    }

    protected function configure()
    {
        parent::configure();
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The url of SQS queue to run, can be a comma separated list')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'The name of a queue in the config, can be a comma separated list')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The visibility timeout for SQS')
            ->addOption('batchSize', null, InputOption::VALUE_REQUIRED, 'Number of messages to fetch at once', 10);
    }

    protected function getJobs()
    {
        if ($this->debug) {
            return [];
        }

        $waitTime = count($this->urls) ? 0 : 20;
        foreach ($this->urls as $url) {
            $jobs = $this->getJobsFromUrl($url, $waitTime);
            if (count($jobs)) {
                return $jobs;
            }
        }

        return [];
    }

    private function getJobsFromUrl($url, $waitTime)
    {
        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $url,
            'WaitTimeSeconds' => $waitTime,
            'VisibilityTimeout' => $this->visibilityTimeout,
            'MaxNumberOfMessages' => $this->batchSize
        ]);

        if (isset($response['Messages'])) {
            return array_filter(array_map(function($message) use($url) {
                $data = json_decode($message['Body'], true);
                if (!isset($data['name']) || !isset($data['arguments']) || !isset($data['retryCount'])) {
                    $this->logger && $this->logger->warning('Found unexpected job data in the queue', [
                        'message' => 'Sqs message missing data fields name, arguments and retryCount',
                        'data' => $data
                    ]);

                    $this->sqs->deleteMessage([
                        'QueueUrl' => $url,
                        'ReceiptHandle' => $message['ReceiptHandle']
                    ]);
                    
                    return false;
                }
                return new SqsJob($data['name'], $data['arguments'], 0, $url, $message['MessageId'], $data['retryCount'], $message['ReceiptHandle']);
            }, $response['Messages']));
        }

        return [];
    }

    protected function finishJobs(array $okJobs, array $retryJobs, array $failedJobs)
    {
        if ($this->debug) {
            return;
        }

        if (count($retryJobs)) {
            $count = 0;
            $this->sqs->sendMessageBatch([
                'QueueUrl' => $retryJobs[0]->getUrl(),
                'Entries' => array_map(function (SqsJob $job) use (&$count) {
                    $count++;
                    $job->incrementRetryCount();

                    return [
                        'Id' => "R{$count}",
                        'MessageBody' => $job->getMessageBody(),
                        'DelaySeconds' => min($job->getRetryCount() * $job->getRetryCount() * 30, 900) //900 is the max delay
                    ];
                }, $retryJobs)
            ]);
        }

        /** @var SqsJob[] $allJobs */
        $allJobs = array_merge($okJobs, $retryJobs, $failedJobs);
        if (count($allJobs)) {
            $count = 0;
            $this->sqs->deleteMessageBatch([
                'QueueUrl' => $allJobs[0]->getUrl(),
                'Entries' => array_map(function (SqsJob $job) use (&$count) {
                    $count++;

                    return [
                        'Id' => "J{$count}",
                        'ReceiptHandle' => $job->getReceiptHandle()
                    ];
                }, array_merge($okJobs, $retryJobs, $failedJobs))
            ]);
        }
    }

    protected function handleInput(InputInterface $input)
    {
        if (($url = $input->getOption('url'))) {
            $this->urls = explode(',', $url);
        } else if (($queue = $input->getOption('queue'))) {
            $this->urls = array_map(function($queue) {
                return $this->queues[$queue];
            }, explode(',', $queue));
        } else {
            $this->urls = [$this->defaultUrl];
        }

        if (($timeout = $input->getOption('timeout'))) {
            $this->visibilityTimeout = $timeout;
        }

        if (($batch = $input->getOption('batchSize'))) {
            $this->batchSize = $batch;
        }
    }
}
