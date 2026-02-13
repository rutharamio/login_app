<?php
function refreshAccessToken(PDO $conn, array $tokenRow): string
{
    if (empty($tokenRow['refresh_token'])) {
        throw new Exception('No hay refresh_token disponible.');
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $tokenRow['refresh_token'],
            'grant_type'    => 'refresh_token',
        ])
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Curl error: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    // Error / invalid_grant (revocado/expirado)
    if ($httpCode !== 200 || empty($data['access_token'])) {
        error_log("TOKEN REFRESH FAILED: " . $response);

        if (!empty($data['error']) && $data['error'] === 'invalid_grant') {
            $stmt = $conn->prepare("
                UPDATE google_gmail_tokens
                SET state='expired', access_token=NULL, expires_at=NULL, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->execute([$tokenRow['id']]);
            throw new Exception('invalid_grant');
        }

        throw new Exception('No se pudo refrescar token Gmail.');
    }

    $expiresIn = (int)($data['expires_in'] ?? 3600);
    // margen de seguridad: refrescar antes de que expire
    $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
        ->modify('+' . max(60, $expiresIn - 60) . ' seconds')
        ->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        UPDATE google_gmail_tokens
        SET access_token=?, expires_at=?, state='active', updated_at=NOW()
        WHERE id=?
    ");
    $stmt->execute([$data['access_token'], $expiresAt, $tokenRow['id']]);

    return $data['access_token'];
}

function getValidAccessToken(PDO $conn, int $userId): string
{
    $stmt = $conn->prepare("
        SELECT id, access_token, refresh_token, expires_at, state
        FROM google_gmail_tokens
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('not_connected');
    }

    if (($row['state'] ?? 'active') !== 'active') {
        throw new Exception('expired');
    }

    // Si no hay expires_at, forzar refresh (porque no podés confiar)
    if (empty($row['expires_at'])) {
        return refreshAccessToken($conn, $row);
    }

    // Comparación en UTC
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));

    // Si expira en <= 60s, refrescar
    if ($exp <= (clone $now)->modify('+60 seconds')) {
        return refreshAccessToken($conn, $row);
    }

    return $row['access_token'];
}
