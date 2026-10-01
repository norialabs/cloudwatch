<?php

declare(strict_types=1);

namespace NoriaLabs\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class CloudWatchHandler extends AbstractProcessingHandler
{
    public const MAX_BATCH_BYTES = 1_048_576;

    public const MAX_BATCH_EVENTS = 10_000;

    public const EVENT_OVERHEAD_BYTES = 26;

    public const MAX_EVENT_BYTES = 262_144;

    public const MAX_BATCH_SPAN_MS = 86_400_000;

    private CloudWatchLogsClient $client;

    private string $logGroup;

    private string $logStreamTemplate;

    private ?int $retention;

    private int $batchSize;

    /** @var array<string, string> */
    private array $tags;

    /** @var array<string, string> */
    private array $streamContext;

    private ?HandlerInterface $fallback;

    private ?string $resolvedStream = null;

    private bool $initialized = false;

    /** @var list<array{timestamp: int, message: string, record: LogRecord}> */
    private array $buffer = [];

    private int $bufferedBytes = 0;

    /**
     * @param  string  $logStream  Stream name or template with placeholders: {app}, {env}, {date}, {hostname}
     * @param  array<string, string>  $tags  Key-value tags applied to the log group on creation.
     * @param  array<string, string>  $streamContext  Values for {app} and {env} placeholders. {date} and {hostname} are always resolved at flush time.
     * @param  HandlerInterface|null  $fallback  Receives events CloudWatch refused, once, instead of the handler throwing.
     */
    public function __construct(
        CloudWatchLogsClient $client,
        string $logGroup,
        string $logStream,
        ?int $retention = 30,
        int $batchSize = 25,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        array $tags = [],
        array $streamContext = [],
        ?HandlerInterface $fallback = null,
    ) {
        parent::__construct($level, $bubble);

        $this->client = $client;
        $this->logGroup = $logGroup;
        $this->logStreamTemplate = $logStream;
        $this->retention = $retention;
        $this->batchSize = min(max(1, $batchSize), self::MAX_BATCH_EVENTS);
        $this->tags = $tags;
        $this->streamContext = $streamContext;
        $this->fallback = $fallback;
    }

    protected function write(LogRecord $record): void
    {
        $message = $this->fitEvent(is_string($record->formatted) ? $record->formatted : $record->message);

        $this->buffer[] = [
            'timestamp' => (int) $record->datetime->format('Uv'),
            'message' => $message,
            'record' => $record,
        ];
        $this->bufferedBytes += strlen($message) + self::EVENT_OVERHEAD_BYTES;

        if (count($this->buffer) >= $this->batchSize || $this->bufferedBytes >= self::MAX_BATCH_BYTES) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $pending = $this->buffer;
        $this->buffer = [];
        $this->bufferedBytes = 0;

        usort($pending, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        $stream = $this->resolveStream();

        if ($stream !== $this->resolvedStream) {
            $this->resolvedStream = $stream;
            $this->initialized = false;
        }

        $failure = null;

        foreach ($this->batches($pending) as $batch) {
            try {
                $this->send($stream, $batch);
            } catch (Throwable $e) {
                $failure ??= $e;
                $this->park($batch, $e);
            }
        }

        if ($failure !== null && $this->fallback === null) {
            throw $failure;
        }
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    protected function getDefaultFormatter(): JsonFormatter
    {
        return new JsonFormatter;
    }

    /**
     * @param  list<array{timestamp: int, message: string, record: LogRecord}>  $events
     * @return list<non-empty-list<array{timestamp: int, message: string, record: LogRecord}>>
     */
    private function batches(array $events): array
    {
        $batches = [];
        $batch = [];
        $bytes = 0;

        foreach ($events as $event) {
            $size = strlen($event['message']) + self::EVENT_OVERHEAD_BYTES;

            if ($batch !== [] && (
                count($batch) >= self::MAX_BATCH_EVENTS
                || $bytes + $size > self::MAX_BATCH_BYTES
                || $event['timestamp'] - $batch[0]['timestamp'] >= self::MAX_BATCH_SPAN_MS
            )) {
                $batches[] = $batch;
                $batch = [];
                $bytes = 0;
            }

            $batch[] = $event;
            $bytes += $size;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /** @param  non-empty-list<array{timestamp: int, message: string, record: LogRecord}>  $batch */
    private function send(string $stream, array $batch): void
    {
        $this->ensureInitialized($stream);

        $request = [
            'logGroupName' => $this->logGroup,
            'logStreamName' => $stream,
            'logEvents' => array_map(
                fn (array $event): array => ['timestamp' => $event['timestamp'], 'message' => $event['message']],
                $batch,
            ),
        ];

        try {
            $this->client->putLogEvents($request);
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }

            $this->initialized = false;
            $this->ensureInitialized($stream);
            $this->client->putLogEvents($request);
        }
    }

    /** @param  non-empty-list<array{timestamp: int, message: string, record: LogRecord}>  $batch */
    private function park(array $batch, Throwable $failure): void
    {
        if ($this->fallback === null) {
            return;
        }

        $first = $batch[0]['record'];

        try {
            $this->fallback->handle(new LogRecord(
                datetime: $first->datetime,
                channel: $first->channel,
                level: Level::Warning,
                message: sprintf('CloudWatch refused %d log events; they follow here instead.', count($batch)),
                context: ['error' => $failure->getMessage(), 'log_group' => $this->logGroup],
            ));
            $this->fallback->handleBatch(array_map(fn (array $event): LogRecord => $event['record'], $batch));
        } catch (Throwable) {
            // The fallback is the last resort: losing it must not fail the request.
        }
    }

    private function fitEvent(string $message): string
    {
        $limit = self::MAX_EVENT_BYTES - self::EVENT_OVERHEAD_BYTES;

        return strlen($message) > $limit ? mb_strcut($message, 0, $limit, 'UTF-8') : $message;
    }

    private function resolveStream(): string
    {
        return str_replace(
            ['{app}', '{env}', '{date}', '{hostname}'],
            [
                $this->streamContext['app'] ?? 'laravel',
                $this->streamContext['env'] ?? 'production',
                date('Y-m-d'),
                gethostname() ?: 'unknown',
            ],
            $this->logStreamTemplate,
        );
    }

    private function ensureInitialized(string $stream): void
    {
        if ($this->initialized) {
            return;
        }

        $this->ensureLogGroupExists();
        $this->ensureLogStreamExists($stream);

        $this->initialized = true;
    }

    private function ensureLogGroupExists(): void
    {
        try {
            $params = ['logGroupName' => $this->logGroup];

            if (! empty($this->tags)) {
                $params['tags'] = $this->tags;
            }

            $this->client->createLogGroup($params);

            if ($this->retention !== null) {
                $this->client->putRetentionPolicy([
                    'logGroupName' => $this->logGroup,
                    'retentionInDays' => $this->retention,
                ]);
            }
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }

    private function ensureLogStreamExists(string $stream): void
    {
        try {
            $this->client->createLogStream([
                'logGroupName' => $this->logGroup,
                'logStreamName' => $stream,
            ]);
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }
}
