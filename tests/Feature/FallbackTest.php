<?php

declare(strict_types=1);

use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use NoriaLabs\CloudWatch\CloudWatchHandler;
use NoriaLabs\CloudWatch\CloudWatchLoggerFactory;

function build(array $channel = []): Logger
{
    $logger = (new CloudWatchLoggerFactory)($channel);

    expect($logger)->toBeInstanceOf(Logger::class);

    return $logger;
}

describe('the fallback', function (): void {
    /*
     * A line CloudWatch will not take is lost without this, which matters
     * most during the outage that makes the endpoint unreachable.
     */
    it('writes beside the stream when the channel names a path', function (): void {
        $handler = build(['fallback_path' => sys_get_temp_dir().'/cwl-test.log'])->getHandlers()[0];

        expect($handler)->toBeInstanceOf(FallbackGroupHandler::class);
    });

    it('tries cloudwatch first and the file only after it', function (): void {
        $handler = build(['fallback_path' => sys_get_temp_dir().'/cwl-test.log'])->getHandlers()[0];

        $handlers = (new ReflectionProperty(FallbackGroupHandler::class, 'handlers'))->getValue($handler);

        expect($handlers[0])->toBeInstanceOf(CloudWatchHandler::class);
        expect($handlers[1])->toBeInstanceOf(RotatingFileHandler::class);
    });

    it('can be set once for every channel rather than on each', function (): void {
        config(['cloudwatch.fallback_path' => sys_get_temp_dir().'/cwl-global.log']);

        expect(build()->getHandlers()[0])->toBeInstanceOf(FallbackGroupHandler::class);
    });

    it('is left out when nobody asked for one', function (): void {
        expect(build()->getHandlers()[0])->toBeInstanceOf(CloudWatchHandler::class);
    });
});

describe('per channel settings', function (): void {
    /*
     * An application channel batching at 25 and an audit channel sending
     * each line immediately are two channels, not two applications.
     */
    it('lets one channel differ from the config every other channel reads', function (): void {
        $logger = build(['name' => 'audit', 'batch_size' => 1, 'log_group' => 'audit-group']);

        expect($logger->getName())->toBe('audit');
    });

    it('falls back to the shared config for whatever the channel left out', function (): void {
        expect(build()->getName())->toBe('cloudwatch');
    });

    it('survives a channel that names nothing at all', function (): void {
        config(['cloudwatch' => []]);

        expect(build())->toBeInstanceOf(Logger::class);
    });
});

describe('the configured level', function (): void {
    it('uses the level the channel named', function (): void {
        config(['cloudwatch.level' => 'warning']);

        expect(build()->getHandlers()[0]->getLevel())->toBe(Level::Warning);
    });

    it('reads it whatever the casing', function (): void {
        config(['cloudwatch.level' => 'ERROR']);

        expect(build()->getHandlers()[0]->getLevel())->toBe(Level::Error);
    });

    /*
     * A typo in one environment variable should not stop the application
     * booting, and too much logging is the safer wrong answer.
     */
    it('falls back to debug rather than throwing on a level nobody recognises', function (): void {
        config(['cloudwatch.level' => 'verbose']);

        expect(build()->getHandlers()[0]->getLevel())->toBe(Level::Debug);
    });
});
