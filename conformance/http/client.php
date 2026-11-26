<?php

declare(strict_types=1);

$request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$socket = stream_socket_client('tcp://127.0.0.1:'.$request['port'], timeout: 5);
if (! is_resource($socket)) {
    throw new RuntimeException('Cannot connect to the loopback relay');
}

try {
    $timeout = $request['timeoutMs'];
    stream_set_timeout($socket, intdiv($timeout, 1000), ($timeout % 1000) * 1000);
    $body = $request['body'];
    $headers = [$request['method'].' '.$request['path'].' HTTP/1.0', 'Host: 127.0.0.1', 'Content-Length: '.strlen($body)];
    foreach ($request['headers'] as $name => $value) {
        $headers[] = $name.': '.$value;
    }
    $wire = implode("\r\n", $headers)."\r\n\r\n".$body;
    while ($wire !== '') {
        $written = fwrite($socket, $wire);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Short write to the loopback relay');
        }
        $wire = substr($wire, $written);
    }
    $statusLine = fgets($socket);
    if ($statusLine === false) {
        $result = ['transport' => stream_get_meta_data($socket)['timed_out'] ? 'deadline' : 'closed'];
    } else {
        if (preg_match('/^HTTP\/1\.[01] (\d{3}) /', $statusLine, $status) !== 1) {
            throw new RuntimeException('Invalid status line from the loopback relay');
        }
        $responseHeaders = [];
        while (($line = fgets($socket)) !== false && $line !== "\r\n") {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower($name)] = trim($value);
        }
        $body = stream_get_contents($socket);
        if (! is_string($body) || strlen($body) !== (int) ($responseHeaders['content-length'] ?? -1)) {
            throw new RuntimeException('Incomplete response from the loopback relay');
        }
        $result = ['status' => (int) $status[1], 'headers' => (object) $responseHeaders, 'body' => $body];
    }
} finally {
    fclose($socket);
}

echo json_encode($result, JSON_THROW_ON_ERROR);
