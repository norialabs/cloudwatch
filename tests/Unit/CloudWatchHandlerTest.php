<?php

declare(strict_types=1);

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Command;
use Aws\Result;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use NoriaLabs\CloudWatch\CloudWatchHandler;

function makeRecord(string $message = 'Test message', Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: $level,
        message: $message,
    );
}

function makeCloudWatchException(string $code): CloudWatchLogsException
{
    return new CloudWatchLogsException(
        $code,
        new Command('test'),
        ['code' => $code],
    );
}

it('buffers logs until batch size is reached', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 2,
    );

    $handler->handle(makeRecord('First'));
    $handler->handle(makeRecord('Second'));
});

it('flushes remaining logs on close', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 100,
    );

    $handler->handle(makeRecord('Buffered'));
    $handler->close();
});

it('does nothing when flushing an empty buffer', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldNotReceive('putLogEvents');
    $client->shouldNotReceive('createLogGroup');
    $client->shouldNotReceive('createLogStream');

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 10,
    );

    $handler->flush();
});

it('sets retention policy when configured', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('putRetentionPolicy')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['retentionInDays'] === 14))
        ->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: 14,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('skips retention policy when set to null', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldNotReceive('putRetentionPolicy');
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('skips initialization when already initialized', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->twice()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord('First'));
    $handler->handle(makeRecord('Second'));
});

it('retries after ResourceNotFoundException by reinitializing', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->twice()->andReturn(new Result);
    $client->shouldReceive('createLogStream')->twice()->andReturn(new Result);

    $client->shouldReceive('putLogEvents')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceNotFoundException'));
    $client->shouldReceive('putLogEvents')
        ->once()
        ->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('rethrows non-ResourceNotFoundException from putLogEvents', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->andReturn(new Result);
    $client->shouldReceive('createLogStream')->andReturn(new Result);
    $client->shouldReceive('putLogEvents')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('ignores ResourceAlreadyExistsException when creating log group', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceAlreadyExistsException'));
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('rethrows non-ResourceAlreadyExistsException from createLogGroup', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('ignores ResourceAlreadyExistsException when creating log stream', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceAlreadyExistsException'));
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('rethrows non-ResourceAlreadyExistsException from createLogStream', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->andReturn(new Result);
    $client->shouldReceive('createLogStream')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('passes tags to createLogGroup when provided', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['tags'] === ['team' => 'backend', 'project' => 'noria']))
        ->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
        tags: ['team' => 'backend', 'project' => 'noria'],
    );

    $handler->handle(makeRecord());
});

it('omits tags from createLogGroup when empty', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->with(Mockery::on(fn ($args) => ! array_key_exists('tags', $args)))
        ->andReturn(new Result);
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
        tags: [],
    );

    $handler->handle(makeRecord());
});

it('resolves stream placeholders at flush time', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $expectedStream = 'myapp-staging-'.date('Y-m-d').'-'.gethostname();

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result);
    $client->shouldReceive('putLogEvents')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: '{app}-{env}-{date}-{hostname}',
        retention: null,
        batchSize: 1,
        streamContext: ['app' => 'myapp', 'env' => 'staging'],
    );

    $handler->handle(makeRecord());
});

it('reinitializes when resolved stream name changes', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->twice()->andReturn(new Result);
    $client->shouldReceive('createLogStream')->twice()->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->twice()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'static-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord('First'));

    $templateRef = new ReflectionProperty($handler, 'logStreamTemplate');
    $templateRef->setValue($handler, 'new-stream');

    $handler->handle(makeRecord('Second'));
});

it('uses default values for missing stream context', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $expectedStream = 'laravel-production';

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result);
    $client->shouldReceive('createLogStream')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result);
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result);

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: '{app}-{env}',
        retention: null,
        batchSize: 1,
        streamContext: [],
    );

    $handler->handle(makeRecord());
});

function recordAt(string $time, string $message = 'Test message'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable($time),
        channel: 'test',
        level: Level::Info,
        message: $message,
    );
}

/** @return array{0: CloudWatchLogsClient, 1: ArrayObject<int, array<string, mixed>>} */
function recordingClient(int $refusals = 0, string $refusal = 'InvalidParameterException'): array
{
    $sent = new ArrayObject;
    $client = Mockery::mock(CloudWatchLogsClient::class);
    $client->shouldReceive('createLogGroup')->andReturn(new Result);
    $client->shouldReceive('createLogStream')->andReturn(new Result);

    if ($refusals > 0) {
        $client->shouldReceive('putLogEvents')->times($refusals)->andThrow(makeCloudWatchException($refusal));
    }

    $client->shouldReceive('putLogEvents')->andReturnUsing(function (array $args) use ($sent) {
        $sent[] = $args;

        return new Result;
    });

    return [$client, $sent];
}

it('splits a flush into batches CloudWatch accepts by size', function () {
    [$client, $sent] = recordingClient();

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 100);

    foreach (range(1, 6) as $i) {
        $handler->handle(makeRecord(str_repeat((string) $i, 240_000)));
    }
    $handler->close();

    expect(array_sum(array_map(fn ($args) => count($args['logEvents']), $sent->getArrayCopy())))->toBe(6)
        ->and(count($sent))->toBeGreaterThan(1);

    foreach ($sent as $args) {
        $bytes = array_sum(array_map(
            fn (array $event) => strlen($event['message']) + CloudWatchHandler::EVENT_OVERHEAD_BYTES,
            $args['logEvents'],
        ));

        expect($bytes)->toBeLessThanOrEqual(CloudWatchHandler::MAX_BATCH_BYTES);
    }
});

it('never sends more events in one batch than CloudWatch allows', function () {
    [$client, $sent] = recordingClient();

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 50_000);
    $handler->setFormatter(new LineFormatter('%message%'));

    foreach (range(1, CloudWatchHandler::MAX_BATCH_EVENTS + 1) as $i) {
        $handler->handle(makeRecord('x'));
    }
    $handler->close();

    expect($sent)->toHaveCount(2)
        ->and(count($sent[0]['logEvents']))->toBe(CloudWatchHandler::MAX_BATCH_EVENTS)
        ->and(count($sent[1]['logEvents']))->toBe(1);
});

it('cuts an event larger than CloudWatch accepts down to the limit', function () {
    [$client, $sent] = recordingClient();

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 1);
    $handler->handle(makeRecord(str_repeat('é', 300_000)));

    $message = $sent[0]['logEvents'][0]['message'];

    expect(strlen($message) + CloudWatchHandler::EVENT_OVERHEAD_BYTES)->toBeLessThanOrEqual(CloudWatchHandler::MAX_EVENT_BYTES)
        ->and(mb_check_encoding($message, 'UTF-8'))->toBeTrue();
});

it('sends events in time order and never lets one batch span a day', function () {
    [$client, $sent] = recordingClient();

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 3);
    $handler->setFormatter(new LineFormatter('%message%'));
    $handler->handle(recordAt('2026-01-02 12:00:00', 'late'));
    $handler->handle(recordAt('2026-01-01 10:00:00', 'early'));
    $handler->handle(recordAt('2026-01-01 11:00:00', 'middle'));

    expect($sent)->toHaveCount(2)
        ->and(array_column($sent[0]['logEvents'], 'message'))->toBe(['early', 'middle'])
        ->and(array_column($sent[1]['logEvents'], 'message'))->toBe(['late']);
});

it('parks a refused batch in the fallback once instead of retrying it forever', function () {
    [$client, $sent] = recordingClient(refusals: 1);
    $fallback = new TestHandler;

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 1, fallback: $fallback);
    $handler->setFormatter(new LineFormatter('%message%'));

    $handler->handle(makeRecord('refused'));
    $handler->handle(makeRecord('accepted'));

    expect($fallback->hasWarningThatContains('CloudWatch refused 1 log events'))->toBeTrue()
        ->and($fallback->hasInfoThatContains('refused'))->toBeTrue()
        ->and($fallback->getRecords())->toHaveCount(2)
        ->and($sent)->toHaveCount(1)
        ->and(array_column($sent[0]['logEvents'], 'message'))->toBe(['accepted']);
});

it('parks the buffer when the stream cannot even be created', function () {
    $fallback = new TestHandler;
    $client = Mockery::mock(CloudWatchLogsClient::class);
    $client->shouldReceive('createLogGroup')->andThrow(makeCloudWatchException('AccessDeniedException'));
    $client->shouldNotReceive('putLogEvents');

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 1, fallback: $fallback);

    $handler->handle(makeRecord('first'));
    $handler->handle(makeRecord('second'));

    expect($fallback->hasInfoThatContains('first'))->toBeTrue()
        ->and($fallback->hasInfoThatContains('second'))->toBeTrue()
        ->and((new ReflectionProperty($handler, 'buffer'))->getValue($handler))->toBe([]);
});

it('drops a refused batch after throwing so the next flush does not resend it', function () {
    [$client, $sent] = recordingClient(refusals: 1);

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 1);
    $handler->setFormatter(new LineFormatter('%message%'));

    expect(fn () => $handler->handle(makeRecord('refused')))->toThrow(CloudWatchLogsException::class);

    $handler->handle(makeRecord('accepted'));

    expect($sent)->toHaveCount(1)
        ->and(array_column($sent[0]['logEvents'], 'message'))->toBe(['accepted']);
});

it('flushes on reset so a long running worker ships each request and job', function () {
    [$client, $sent] = recordingClient();

    $handler = new CloudWatchHandler(client: $client, logGroup: 'g', logStream: 's', retention: null, batchSize: 100);
    $handler->handle(makeRecord('request'));

    expect($sent)->toHaveCount(0);

    $handler->reset();

    expect($sent)->toHaveCount(1);

    $handler->handle(makeRecord('next request'));
    $handler->reset();

    expect($sent)->toHaveCount(2);
});
