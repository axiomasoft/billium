<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class ChildProcess
{
    /** @var resource */
    private $process;

    /** @var array<int,resource> */
    private array $pipes;

    public readonly int $pid;

    /**
     * @param  non-empty-list<string>  $command
     * @param  array<string,string>|null  $environment
     */
    public function __construct(array $command, ?array $environment = null)
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start child process');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->pid = proc_get_status($process)['pid'];
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
    }

    public function wait(): int
    {
        $output = '';
        foreach ($this->pipes as $pipe) {
            $bytes = stream_get_contents($pipe);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read child output');
            } $output .= $bytes;
            fclose($pipe);
        }
        $this->pipes = [];
        $exit = proc_close($this->process);
        if ($exit !== 0 && $output !== '') {
            throw new RuntimeException('Child process failed: '.$output);
        }

        return $exit;
    }

    public function kill(): void
    {
        if (! proc_terminate($this->process, 9)) {
            throw new RuntimeException('Cannot kill child process');
        }
    }
}
