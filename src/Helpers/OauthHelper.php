<?php

declare(strict_types=1);

namespace FastREST\Helpers;

/**
 * OAuth 1.0 Authorization header generator.
 *
 * Fix from original: oauth_timestamp was duplicated in the header when a
 * token was present. The second occurrence is now correctly oauth_token.
 */
class OauthHelper
{
    /**
     * Generate OAuth 1.0 Authorization headers.
     *
     * @param string      $method          HTTP method (GET, POST, …)
     * @param string      $url             Full endpoint URL
     * @param string      $consumerKey     OAuth consumer key
     * @param string      $consumerSecret  OAuth consumer secret
     * @param string|null $token           Optional access token
     * @param string|null $tokenSecret     Optional access token secret
     *
     * @return string[] Array of HTTP header strings ready for cURL
     */
    public static function buildAuthorizationHeaders(
        string  $method,
        string  $url,
        string  $consumerKey,
        string  $consumerSecret,
        ?string $token       = null,
        ?string $tokenSecret = null,
    ): array {
        $nonce   = bin2hex(random_bytes(16)); // cryptographically random, unlike str_shuffle
        $timestamp = time();

        $params = [
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) $timestamp,
            'oauth_version'          => '1.0',
        ];

        if ($token !== null) {
            $params['oauth_token'] = $token;
        }

        ksort($params);

        // Build the signature base string
        $baseString = strtoupper($method)
            . '&' . rawurlencode($url)
            . '&' . rawurlencode(http_build_query($params));

        $signingKey = rawurlencode($consumerSecret)
            . '&'
            . ($tokenSecret !== null ? rawurlencode($tokenSecret) : '');

        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        // Build the Authorization header value
        $headerParts = [
            'oauth_consumer_key="'     . $params['oauth_consumer_key']     . '"',
            'oauth_nonce="'            . $params['oauth_nonce']            . '"',
            'oauth_signature="'        . rawurlencode($signature)          . '"',
            'oauth_signature_method="' . $params['oauth_signature_method'] . '"',
            'oauth_timestamp="'        . $params['oauth_timestamp']        . '"',
            'oauth_version="'          . $params['oauth_version']          . '"',
        ];

        // ← FIX: original duplicated oauth_timestamp here; it should be oauth_token
        if ($token !== null) {
            $headerParts[] = 'oauth_token="' . $token . '"';
        }

        return [
            'Authorization: OAuth ' . implode(', ', $headerParts),
            'Content-Type: application/json; charset=utf-8',
        ];
    }
}
