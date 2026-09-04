<?php
/**
 * Talking to Njiwa. Transport only.
 *
 * Everything goes through Magento's own HTTP client rather than curl calls of
 * its own, so a shop behind a proxy, or one whose host has opinions about
 * outbound requests, behaves here the way it does everywhere else in Magento.
 * Nothing in this class decides when to message anybody.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Upeo\Njiwa\Exception\NjiwaException;

class Client
{
    /** Long enough for a slow line, short enough that nothing holds a worker. */
    private const TIMEOUT = 20;

    private const MODULE_NAME = 'Upeo_Njiwa';

    /**
     * @var ClientInterface
     */
    private $http;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    public function __construct(
        ClientInterface $http,
        Config $config,
        Json $json,
        ModuleListInterface $moduleList
    ) {
        $this->http = $http;
        $this->config = $config;
        $this->json = $json;
        $this->moduleList = $moduleList;
    }

    /**
     * Send one text message.
     *
     * @param string $to Recipient, digits only.
     * @param string $text The message.
     * @param string $idempotencyKey Njiwa honours it for twenty-four hours,
     *        so a worker that runs the same job twice replays the first
     *        answer instead of messaging the customer again.
     * @return array Njiwa's answer, including the message id.
     * @throws NjiwaException
     */
    public function sendText(string $to, string $text, string $idempotencyKey = '', ?int $storeId = null): array
    {
        $body = [
            'to' => $to,
            'text' => $text,
        ];

        // Only when the shop named a number. Left out, Njiwa uses the
        // account's default.
        $from = $this->config->from($storeId);
        if ($from !== '') {
            $body['from'] = $from;
        }

        $headers = [];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', '/v1/messages', $body, $headers, $storeId);
    }

    /**
     * The WhatsApp numbers on this account, linked or not.
     *
     * @return array<int,array<string,mixed>>
     * @throws NjiwaException
     */
    public function numbers(?int $storeId = null): array
    {
        $answer = $this->request('GET', '/v1/instances', null, [], $storeId);

        return isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : [];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws NjiwaException
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        array $headers,
        ?int $storeId
    ): array {
        // The master switch has to fail here rather than shrug. Somebody who
        // turned it off and forgot needs to find a line in a log saying so,
        // not silence that looks exactly like a working shop.
        if (!$this->config->isEnabled($storeId)) {
            throw new NjiwaException(
                __('Send WhatsApp messages is set to No in Stores, Configuration, Njiwa WhatsApp, so nothing was sent.'),
                'disabled'
            );
        }

        $key = $this->config->apiKey($storeId);
        if ($key === '') {
            throw new NjiwaException(
                __('There is no Njiwa API key saved, so nothing can be sent.'),
                'not_configured'
            );
        }

        $url = $this->config->baseUrl($storeId) . $path;

        $headers = array_merge(
            [
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
                'User-Agent' => 'njiwa-magento/' . $this->version(),
            ],
            $headers
        );

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = $this->json->serialize($body);
        }

        // The client is shared, so every request sets its own headers rather
        // than inheriting whatever the last one left behind.
        $this->http->setHeaders($headers);
        $this->http->setTimeout(self::TIMEOUT);

        try {
            if ($method === 'POST') {
                $this->http->post($url, (string) $payload);
            } else {
                $this->http->get($url);
            }
        } catch (\Exception $e) {
            // A network failure is not a send failure: the message was never
            // accepted, so trying again later would be safe.
            throw new NjiwaException(
                __('Could not reach Njiwa at %1. %2', $this->config->baseUrl($storeId), $e->getMessage()),
                'connection_failed',
                0,
                null,
                $e
            );
        }

        $status = (int) $this->http->getStatus();
        $decoded = $this->decode((string) $this->http->getBody());

        if ($status >= 400) {
            $error = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];

            throw new NjiwaException(
                isset($error['message'])
                    ? __('%1', (string) $error['message'])
                    : __('Njiwa answered with HTTP %1.', $status),
                isset($error['code']) ? (string) $error['code'] : 'unknown',
                $status,
                isset($error['docs']) ? (string) $error['docs'] : null
            );
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            // Almost always a proxy or a WAF answering instead of Njiwa. The
            // status code below says more than the HTML body would.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The version in etc/module.xml, so the User-Agent cannot drift from what
     * the shop actually has installed.
     */
    private function version(): string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);

        return isset($module['setup_version']) ? (string) $module['setup_version'] : '0.0.0';
    }
}
