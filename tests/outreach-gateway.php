<?php

declare(strict_types=1);

use CustomerSuite\Input;
use CustomerSuite\OutreachGateway;

require dirname(__DIR__) . '/src/bootstrap.php';

const TEST_SECRET = 'local-fixture-key-not-for-deployment';
const TEST_KEY = '389660bb205c4b1246edbb20c64d90758b3db5b7637c2534ad7663ef596b16d3';

if (($argv[1] ?? null) === 'receiver') {
    $server = stream_socket_server('tls://127.0.0.1:0', $number, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        stream_context_create(['ssl' => ['local_cert' => $argv[2]]]));
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start local TLS receiver: ' . $error);
    }
    echo stream_socket_get_name($server, false), "\n";
    fflush(STDOUT);
    for ($i = 0; $i < 3; $i++) {
        $client = stream_socket_accept($server, 12);
        if (!is_resource($client)) {
            throw new RuntimeException('Outreach gateway did not connect to the local fixture.');
        }
        stream_set_timeout($client, 6);
        $line = fgets($client);
        if (!is_string($line) || !str_starts_with($line, 'POST /dispatch HTTP/')) {
            throw new RuntimeException('Unexpected local webhook request line.');
        }
        $headers = [];
        while (($line = fgets($client)) !== false && trim($line) !== '') {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        if ($length < 1 || $length > 4096) {
            throw new RuntimeException('Unexpected local webhook body length.');
        }
        $body = stream_get_contents($client, $length);
        $timestamp = $headers['x-crm-timestamp'] ?? '0';
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, TEST_SECRET);
        if (!hash_equals($signature, $headers['x-crm-signature'] ?? '') || abs(time() - (int) $timestamp) > 10
            || ($headers['idempotency-key'] ?? null) !== TEST_KEY
        ) {
            throw new RuntimeException('Outreach request HMAC, timestamp or idempotency key did not match.');
        }
        $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (($payload['address'] ?? null) !== 'fixture@example.test' || ($payload['channel'] ?? null) !== 'email'
            || ($payload['idempotency_key'] ?? null) !== TEST_KEY) {
            throw new RuntimeException('Unexpected synthetic outreach payload.');
        }
        [$code, $receiptKey] = match ($payload['message']) {
            'accept' => ['202 Accepted', TEST_KEY],
            'wrong-key' => ['202 Accepted', 'some-other-operation'],
            'unavailable' => ['503 Service Unavailable', TEST_KEY],
            default => throw new RuntimeException('Unexpected synthetic provider scenario.'),
        };
        $response = Input::json(['idempotency_key' => $receiptKey, 'reference' => 'provider-receipt-1']);
        fwrite($client, "HTTP/1.1 $code\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: "
            . strlen($response) . "\r\n\r\n" . $response);
        fclose($client);
    }
    fclose($server);
    exit(0);
}

if (($argv[1] ?? null) === 'sender') {
    putenv('CRM_OUTREACH_MODE=webhook');
    putenv('CRM_OUTREACH_WEBHOOK=https://' . $argv[2] . '/dispatch');
    putenv('CRM_OUTREACH_SECRET=' . TEST_SECRET);
    foreach (['accept' => 'delivered', 'wrong-key' => 'retryable', 'unavailable' => 'retryable'] as $message => $expected) {
        $result = OutreachGateway::submit('webhook', TEST_KEY, 'email', 'fixture@example.test', $message);
        if ($result['state'] !== $expected || ($expected === 'delivered' && $result['reference'] !== 'provider-receipt-1')
            || ($expected === 'retryable' && $result['error'] !== 'gateway_unconfirmed')) {
            throw new RuntimeException('Unexpected webhook outcome for ' . $message . ': ' . Input::json($result));
        }
    }
    exit(0);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-tls-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create TLS fixture directory.');
}
$config = $directory . '/openssl.cnf';
$certificate = $directory . '/certificate.pem';
$combined = $directory . '/receiver.pem';
$process = null;
try {
    file_put_contents($config, "[req]\ndistinguished_name = distinguished_name\nx509_extensions = extensions\n"
        . "[distinguished_name]\n[extensions]\nsubjectAltName = IP:127.0.0.1\nbasicConstraints = critical,CA:TRUE\n");
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'x509_extensions' => 'extensions'];
    $private = openssl_pkey_new($options);
    $request = $private === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $private, $options);
    $signed = $request === false ? false : openssl_csr_sign($request, null, $private, 1, $options);
    if ($signed === false || !openssl_x509_export($signed, $certBody) || !openssl_pkey_export($private, $keyBody, null, $options)) {
        throw new RuntimeException('Could not generate local TLS fixture certificate.');
    }
    file_put_contents($certificate, $certBody);
    file_put_contents($combined, $certBody . $keyBody);
    $process = proc_open([PHP_BINARY, __FILE__, 'receiver', $combined],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start local TLS fixture process.');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    $host = trim((string) fgets($pipes[1]));
    if (!preg_match('/^127\.0\.0\.1:\d+$/D', $host)) {
        throw new RuntimeException('Unexpected TLS fixture address. ' . stream_get_contents($pipes[2]));
    }
    $sender = proc_open([PHP_BINARY, '-d', 'openssl.cafile=' . $certificate, __FILE__, 'sender', $host],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $senderPipes);
    if (!is_resource($sender)) {
        throw new RuntimeException('Could not start local webhook client.');
    }
    fclose($senderPipes[0]);
    if (proc_close($sender) !== 0) {
        throw new RuntimeException('Local webhook client did not pass.');
    }
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Local TLS receiver did not pass. ' . $errors);
    }
    echo "Signed outreach webhook over TLS: 3 scenarios passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ([$combined, $certificate, $config] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
