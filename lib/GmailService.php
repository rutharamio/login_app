<?php

class GmailService
{
    private PDO $db;
    private array $tokenData;
    private string $accessToken;
    private int $userId;

    public function __construct(PDO $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;

        $this->loadTokens();
        $this->ensureValidAccessToken();
    }

    // TOKEN MANAGEMENT

    private function loadTokens(): void
    {
        $stmt = $this->db->prepare("
            SELECT access_token, refresh_token, expires_at, state
            FROM google_gmail_tokens
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$this->userId]);
        $this->tokenData = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$this->tokenData || $this->tokenData['state'] !== 'active') {
            throw new Exception('Gmail no conectado o expirado.');
        }

        $this->accessToken = $this->tokenData['access_token'];
    }

    private function ensureValidAccessToken(): void
    {
        $expiresAt = strtotime($this->tokenData['expires_at']);

        // Token todavía válido
        if (time() < $expiresAt - 60) {
            return;
        }

        // No hay refresh token → expirado
        if (empty($this->tokenData['refresh_token'])) {
            $this->markExpired();
            throw new Exception('No hay refresh token disponible.');
        }

        try {
            $data = [
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'refresh_token' => $this->tokenData['refresh_token'],
                'grant_type'    => 'refresh_token'
            ];

            $ch = curl_init(GOOGLE_TOKEN_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($data)
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $newToken = json_decode($response, true);

            // Google respondió error (invalid_grant, etc.)
            if (empty($newToken['access_token'])) {
                $this->markExpired();
                throw new Exception(
                    'Error refrescando token: ' . ($newToken['error'] ?? 'unknown')
                );
            }

            // Éxito → actualizar token
            $this->accessToken = $newToken['access_token'];
            $newExpiresAt = date('Y-m-d H:i:s', time() + $newToken['expires_in']);

            $upd = $this->db->prepare("
                UPDATE google_gmail_tokens
                SET access_token = ?, expires_at = ?
                WHERE user_id = ?
            ");
            $upd->execute([$this->accessToken, $newExpiresAt, $this->userId]);

        } catch (Throwable $e) {
            // CUALQUIER error → expirado
            $this->markExpired();
            throw $e;
        }
    }


    // HTTP CLIENT

    private function request(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new Exception("Gmail API error ($status): $response");
        }

        return json_decode($response, true);
    }

    // GMAIL API METHODS

    public function listThreads(?string $pageToken = null): array
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/threads?maxResults=100&labelIds=INBOX';

        if ($pageToken) {
            $url .= '&pageToken=' . urlencode($pageToken);
        }

        return $this->request('GET', $url);
    }

    public function getThread(string $threadId): array
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/threads/' . urlencode($threadId) . '?format=full';
        return $this->request('GET', $url);
    }

    public function getAttachment(string $messageId, string $attachmentId): string
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' .
               urlencode($messageId) . '/attachments/' . urlencode($attachmentId);

        $data = $this->request('GET', $url);

        if (empty($data['data'])) {
            throw new Exception('Adjunto vacío.');
        }

        return base64_decode(strtr($data['data'], '-_', '+/'));
    }

    private function markExpired(): void
    {
    $this->db->prepare("
        UPDATE google_gmail_tokens
        SET state = 'expired'
        WHERE user_id = ?
    ")->execute([$this->userId]);
    }

}