<?php

declare(strict_types=1);

namespace CustomerSuite;

final class OutreachGateway
{
    public static function mode(): string
    {
        return Input::choice(getenv('CRM_OUTREACH_MODE') ?: 'replay', '触达运行模式', ['replay', 'webhook']);
    }

    public static function submit(string $mode, string $key, string $channel, string $address, string $message): array
    {
        if ($mode !== (getenv('CRM_OUTREACH_MODE') ?: 'replay')) {
            return ['state' => 'retryable', 'error' => 'gateway_configuration'];
        }
        if ($mode === 'replay') {
            return ['state' => 'replayed', 'reference' => 'replay-' . substr($key, 0, 24)];
        }
        $url = getenv('CRM_OUTREACH_WEBHOOK') ?: '';
        $secret = getenv('CRM_OUTREACH_SECRET') ?: '';
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['fragment']) || strlen($secret) < 32
        ) {
            return ['state' => 'retryable', 'error' => 'gateway_configuration'];
        }
        $payload = Input::json(['idempotency_key' => $key, 'channel' => $channel, 'address' => $address, 'message' => $message]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Idempotency-Key: ' . $key,
                    'X-CRM-Timestamp: ' . $timestamp,
                    'X-CRM-Signature: sha256=' . $signature,
                ],
                'content' => $payload,
                'timeout' => 4,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return ['state' => 'retryable', 'error' => 'gateway_unconfirmed'];
        }
        try {
            $status = stream_get_meta_data($stream)['wrapper_data'][0] ?? '';
            $body = stream_get_contents($stream, 4097);
            if (!preg_match('/^HTTP\/[\d.]+ 2[0-9]{2}\b/', $status) || $body === false || strlen($body) > 4096
                || stream_get_meta_data($stream)['timed_out']) {
                return ['state' => 'retryable', 'error' => 'gateway_unconfirmed'];
            }
            $receipt = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($receipt) || ($receipt['idempotency_key'] ?? null) !== $key) {
                return ['state' => 'retryable', 'error' => 'gateway_unconfirmed'];
            }
            $reference = Input::key($receipt['reference'] ?? null, '渠道回执编号', 160);
            return ['state' => 'delivered', 'reference' => $reference];
        } catch (\JsonException|BusinessError) {
            return ['state' => 'retryable', 'error' => 'gateway_unconfirmed'];
        } finally {
            fclose($stream);
        }
    }
}
