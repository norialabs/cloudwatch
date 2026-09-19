<?php

declare(strict_types=1);

namespace NoriaLabs\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Builds the `cloudwatch` log channel.
 *
 * Every setting may be given per channel and falls back to the cloudwatch
 * config, so two channels can differ - an application channel batching at
 * 25 and an audit channel sending each line immediately.
 *
 * With a fallback_path the handler is wrapped so a line that CloudWatch
 * will not take is written to a rotating file instead. Without one, an
 * unreachable endpoint loses the line, which is the wrong trade for the
 * logs you want most during an outage.
 */
class CloudWatchLoggerFactory
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config): LoggerInterface
    {
        $settings = $this->settings($config);

        $handler = new CloudWatchHandler(
            client: new CloudWatchLogsClient($this->clientConfig($settings)),
            logGroup: $settings['log_group'],
            logStream: $settings['log_stream'],
            retention: $settings['retention'],
            batchSize: $settings['batch_size'],
            level: $this->level($settings['level']),
            tags: $settings['tags'],
            streamContext: [
                'app' => $this->text(config('app.name'), 'laravel'),
                'env' => $this->text(config('app.env'), 'production'),
            ],
        );

        $handler->setFormatter(new JsonFormatter);

        return new Logger($this->text($config['name'] ?? null, 'cloudwatch'), [
            $this->withFallback($handler, $config, $settings['level']),
        ]);
    }

    /**
     * A rotating file beside the stream. FallbackGroupHandler tries each in
     * turn and stops at the first that does not throw, so the line is
     * written once when CloudWatch is up and once locally when it is not.
     *
     * @param  array<string, mixed>  $config
     */
    private function withFallback(CloudWatchHandler $handler, array $config, string $level): HandlerInterface
    {
        $global = (array) config('cloudwatch', []);
        $path = $this->nullableText($config['fallback_path'] ?? $global['fallback_path'] ?? null);

        if ($path === null) {
            return $handler;
        }

        return new FallbackGroupHandler([
            $handler,
            new RotatingFileHandler(
                $path,
                $this->positiveInt($config['fallback_days'] ?? $global['fallback_days'] ?? null, 14),
                $this->level($level),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{credentials: array{key: string|null, secret: string|null}, region: string, version: string, log_group: string, log_stream: string, retention: int|null, batch_size: int, level: string, tags: array<string, string>}
     */
    private function settings(array $config): array
    {
        $global = (array) config('cloudwatch', []);
        $credentials = (array) ($config['credentials'] ?? $global['credentials'] ?? []);

        return [
            'credentials' => [
                'key' => $this->nullableText($credentials['key'] ?? null),
                'secret' => $this->nullableText($credentials['secret'] ?? null),
            ],
            'region' => $this->text($config['region'] ?? $global['region'] ?? null, 'us-east-1'),
            'version' => $this->text($config['version'] ?? $global['version'] ?? null, 'latest'),
            'log_group' => $this->text($config['log_group'] ?? $global['log_group'] ?? null, 'laravel'),
            'log_stream' => $this->text($config['log_stream'] ?? $global['log_stream'] ?? null, '{app}-{env}'),
            'retention' => $this->nullableInt($config['retention'] ?? $global['retention'] ?? null),
            'batch_size' => $this->positiveInt($config['batch_size'] ?? $global['batch_size'] ?? null, 25),
            'level' => $this->text($config['level'] ?? $global['level'] ?? null, 'debug'),
            'tags' => $this->textMap($config['tags'] ?? $global['tags'] ?? []),
        ];
    }

    /**
     * Credentials are passed only when both halves are present, so an
     * incomplete pair falls through to the default provider chain rather
     * than failing as a bad signature.
     *
     * @param  array{credentials: array{key: string|null, secret: string|null}, region: string, version: string, ...}  $settings
     * @return array<string, mixed>
     */
    private function clientConfig(array $settings): array
    {
        $client = ['region' => $settings['region'], 'version' => $settings['version']];
        $key = $settings['credentials']['key'];
        $secret = $settings['credentials']['secret'];

        if ($key !== null && $secret !== null) {
            $client['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        return $client;
    }

    /**
     * A level nobody recognises falls back to debug rather than throwing
     * out of channel construction: a typo in one environment variable
     * should not stop the application booting, and too much logging is
     * the safer wrong answer.
     */
    private function level(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if (strcasecmp($level->getName(), $name) === 0) {
                return $level;
            }
        }

        return Level::Debug;
    }

    private function text(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private function nullableText(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $int = is_numeric($value) ? (int) $value : $default;

        return $int > 0 ? $int : $default;
    }

    /** @return array<string, string> */
    private function textMap(mixed $value): array
    {
        $map = [];

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && is_scalar($item)) {
                    $map[$key] = (string) $item;
                }
            }
        }

        return $map;
    }
}
