<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments;

class CloudpaymentsApiClient
{
    private const API_URL = 'https://api.cloudpayments.ru';

    /**
     * @var string
     */
    private $publishableKey;

    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var string
     */
    private $cultureName;

    /**
     * @var string
     */
    private $apiUrl;

    public function __construct($publishableKey, $secretKey, $cultureName = 'ru-RU', $apiUrl = null)
    {
        $this->publishableKey = $publishableKey;
        $this->secretKey = $secretKey;
        $this->cultureName = $cultureName;
        $this->apiUrl = rtrim($apiUrl ?: (getenv('CLOUDPAYMENTS_API_URL') ?: self::API_URL), '/');
        $apiUrlParts = parse_url($this->apiUrl);
        $isLocalApi = isset($apiUrlParts['host']) && in_array($apiUrlParts['host'], ['127.0.0.1', 'localhost'], true);
        if (!isset($apiUrlParts['scheme'], $apiUrlParts['host']) || ($apiUrlParts['scheme'] !== 'https' && !$isLocalApi)) {
            throw new \InvalidArgumentException('CloudPayments API URL must use HTTPS unless it points to localhost.');
        }
    }

    public function createSbpPaymentLink(array $payload, $requestId): array
    {
        return $this->sendRequest('/payments/qr/sbp/link', $payload, $requestId);
    }

    public function chargeCard(array $payload, $requestId): array
    {
        return $this->sendRequest('/payments/cards/charge', $payload, $requestId);
    }

    public function confirm3ds(array $payload, $requestId): array
    {
        return $this->sendRequest('/payments/cards/post3ds', $payload, $requestId);
    }

    private function sendRequest($endpoint, array $payload, $requestId = null): array
    {
        $payload['CultureName'] = $this->cultureName;

        $headers = ['Content-Type: application/json'];
        if ($requestId) {
            $headers[] = 'X-Request-ID: ' . $requestId;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->apiUrl . $endpoint);
        curl_setopt($curl, CURLOPT_USERPWD, sprintf('%s:%s', $this->publishableKey, $this->secretKey));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            curl_close($curl);

            throw new \RuntimeException('CloudPayments request payload could not be encoded as JSON.');
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false) {
            throw new \RuntimeException('CloudPayments request failed: ' . $curlError);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('CloudPayments request failed with HTTP %s.', $statusCode));
        }

        $response = json_decode($result, true);
        if (!is_array($response)) {
            throw new \RuntimeException('CloudPayments returned an invalid JSON response.');
        }

        return $response;
    }
}
