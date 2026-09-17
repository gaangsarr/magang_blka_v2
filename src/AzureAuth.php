<?php

declare(strict_types=1);

namespace App;

use Exception;

/**
 * AzureAuth Helper
 *
 * Mengelola alur OAuth 2.0 Authorization Code Flow langsung ke Microsoft Entra ID (Azure AD)
 * dan integrasi dengan Microsoft Graph API (/v1.0/me) tanpa perantara Firebase.
 */
class AzureAuth
{
    private const DEFAULT_TENANT = '7b388d18-1900-418c-a5d3-e28d7a9a38e6';
    private const SCOPES = 'openid profile email User.Read';

    public static function getTenantId(): string
    {
        return !empty($_ENV['AZURE_TENANT_ID']) ? trim($_ENV['AZURE_TENANT_ID']) : self::DEFAULT_TENANT;
    }

    public static function getClientId(): string
    {
        return trim($_ENV['AZURE_CLIENT_ID'] ?? '');
    }

    public static function getClientSecret(): string
    {
        return trim($_ENV['AZURE_CLIENT_SECRET'] ?? '');
    }

    public static function getRedirectUri(): string
    {
        if (!empty($_ENV['AZURE_REDIRECT_URI'])) {
            return trim($_ENV['AZURE_REDIRECT_URI']);
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
        return $appUrl . '/api/auth/azure/callback.php';
    }

    /**
     * Memeriksa apakah kredensial Azure sudah lengkap di .env
     */
    public static function isConfigured(): bool
    {
        $clientId = self::getClientId();
        $secret   = self::getClientSecret();

        return !empty($clientId) 
            && !empty($secret) 
            && !str_contains($clientId, 'your-') 
            && !str_contains($secret, 'your-');
    }

    /**
     * Memeriksa apakah mode Dev Mock diizinkan (hanya saat APP_ENV=local dan kredensial belum ada / testing)
     */
    public static function isDevMockAllowed(): bool
    {
        $appEnv = strtolower(trim($_ENV['APP_ENV'] ?? 'local'));
        return ($appEnv === 'local' || $appEnv === 'development');
    }

    /**
     * Menghasilkan URL otorisasi Microsoft OAuth 2.0
     */
    public static function getAuthorizationUrl(string $state): string
    {
        $tenantId = self::getTenantId();
        $endpoint = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize";

        $params = [
            'client_id'             => self::getClientId(),
            'response_type'         => 'code',
            'redirect_uri'          => self::getRedirectUri(),
            'response_mode'         => 'query',
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'prompt'                => 'select_account',
        ];

        return $endpoint . '?' . http_build_query($params);
    }

    /**
     * Menukar authorization code dengan Access Token & ID Token ke Microsoft token endpoint
     *
     * @return array{access_token: string, id_token: string, expires_in?: int, token_type?: string}
     * @throws Exception
     */
    public static function exchangeCodeForToken(string $code): array
    {
        $tenantId = self::getTenantId();
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        $postData = [
            'client_id'     => self::getClientId(),
            'client_secret' => self::getClientSecret(),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::getRedirectUri(),
            'scope'         => self::SCOPES,
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Koneksi ke server Microsoft gagal: ' . $curlErr);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data) || empty($data['access_token'])) {
            $errDesc = $data['error_description'] ?? $data['error'] ?? 'Gagal menukar authorization code dengan token.';
            throw new Exception('Microsoft Token Error (' . $httpCode . '): ' . $errDesc);
        }

        return $data;
    }

    /**
     * Memanggil Microsoft Graph API (/v1.0/me) untuk mengambil data profil pengguna
     *
     * @return array{id: string, displayName: string, mail: string, userPrincipalName: string}
     * @throws Exception
     */
    public static function getUserProfile(string $accessToken): array
    {
        $graphUrl = 'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName';

        $ch = curl_init($graphUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Gagal menghubungi Microsoft Graph API: ' . $curlErr);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? 'Gagal mengambil data profil Microsoft.';
            throw new Exception('Microsoft Graph Error (' . $httpCode . '): ' . $msg);
        }

        $email = !empty($data['mail']) ? $data['mail'] : ($data['userPrincipalName'] ?? '');

        return [
            'id'                => (string)($data['id'] ?? ''),
            'displayName'       => (string)($data['displayName'] ?? ''),
            'mail'              => strtolower(trim((string)$email)),
            'userPrincipalName' => strtolower(trim((string)($data['userPrincipalName'] ?? ''))),
        ];
    }
}
