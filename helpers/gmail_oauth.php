<?php

function refreshAccessToken(PDO $conn, array $tokenRow): string
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $tokenRow['refresh_token'],
            'grant_type'    => 'refresh_token'
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        error_log("TOKEN REFRESH FAILED: " . $response);
        throw new Exception('No se pudo refrescar token Gmail');
    }

    $stmt = $conn->prepare("
        UPDATE google_gmail_tokens
        SET access_token = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $data['access_token'],
        $tokenRow['id']
    ]);

    return $data['access_token'];
}