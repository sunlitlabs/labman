<?php

namespace Tests\Concerns;

use Symfony\Component\Process\Process;

/**
 * Real local endpoints for the health-check tests: PHP's built-in web server as a fake
 * backend, plain TCP listeners for "open" ports, and freed ports for "closed" ones.
 */
trait UsesLocalServers
{
    /** @var list<Process> */
    private array $httpProcesses = [];

    /** @var list<resource> */
    private array $listeners = [];

    protected function startHttpServer(): int
    {
        $port = $this->closedPort();

        $process = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__).'/Fixtures/router.php'],
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        $process->start();
        $this->httpProcesses[] = $process;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }

        $this->fail('The local PHP test server did not start: '.$process->getErrorOutput());
    }

    /** A port with a live listener (the kernel completes handshakes, no accept needed). */
    protected function openPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, "cannot listen: {$errstr}");
        $this->listeners[] = $listener;

        return (int) explode(':', stream_socket_get_name($listener, false))[1];
    }

    /** A port nothing listens on: bind to get a free one, then release it. */
    protected function closedPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($listener, false))[1];
        fclose($listener);

        return $port;
    }

    protected function tearDownUsesLocalServers(): void
    {
        foreach ($this->httpProcesses as $process) {
            $process->stop(0);
        }
        foreach ($this->listeners as $listener) {
            @fclose($listener);
        }
        $this->httpProcesses = [];
        $this->listeners = [];
    }
}
