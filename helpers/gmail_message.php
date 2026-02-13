<?php

function extractEmailBody(array $payload): array { 

    $bodyText = '';
    $bodyHtml = '';

    $walk = function ($part) use (&$walk, &$bodyText, &$bodyHtml) {

        if (!is_array($part)) {
            return;
        }

        $mime = $part['mimeType'] ?? '';

        if ($mime === 'text/plain' && !empty($part['body']['data'])) {
            $bodyText .= base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }

        if ($mime === 'text/html' && !empty($part['body']['data'])) {
            $bodyHtml .= base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }

        if (!empty($part['parts'])) {
            foreach ($part['parts'] as $p) {
                $walk($p);
            }
        }
    };

    $walk($payload);

    return [
        'text' => trim($bodyText),
        'html' => trim($bodyHtml),
    ];
}

function extractAttachments(array $payload): array {

    $attachments = [];

    $walk = function ($part) use (&$attachments, &$walk) {

        if (!is_array($part)) return;

        $filename = $part['filename'] ?? '';
        $mime     = $part['mimeType'] ?? 'application/octet-stream';
        $body     = $part['body'] ?? [];

        if ($filename !== '') {

            // Caso 1: Attachment externo (attachmentId)
            if (!empty($body['attachmentId'])) {

                $attachments[] = [
                    'filename'      => $filename,
                    'mime_type'     => $mime,
                    'attachment_id' => $body['attachmentId'],
                    'size_bytes'    => $body['size'] ?? 0,
                    'inline_data'   => null
                ];
            }

            // Caso 2: Attachment embebido directamente (body.data)
            elseif (!empty($body['data'])) {

                $attachments[] = [
                    'filename'      => $filename,
                    'mime_type'     => $mime,
                    'attachment_id' => null,
                    'size_bytes'    => $body['size'] ?? 0,
                    'inline_data'   => base64_decode(strtr($body['data'], '-_', '+/'))
                ];
            }
        }

        if (!empty($part['parts']) && is_array($part['parts'])) {
            foreach ($part['parts'] as $p) {
                $walk($p);
            }
        }
    };

    $walk($payload);

    return $attachments;
}

function downloadGmailAttachment(
    string $accessToken,
    string $messageId,
    string $attachmentId
): ?string {

    $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GMAIL ATTACHMENT HTTP CODE: " . $httpCode);
    error_log("GMAIL ATTACHMENT RAW RESPONSE: " . $response);


    $data = json_decode($response, true);

    if (empty($data['data'])) {
        return null;
    }

    return base64_decode(strtr($data['data'], '-_', '+/'));
}

function fetchGmailMessageFull(
    string $accessToken,
    string $messageId
): array { 

    $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/"
         . urlencode($messageId)
         . "?format=full";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GMAIL MESSAGE FULL HTTP CODE: " . $httpCode . " msgId=" . $messageId);

    if ($httpCode !== 200) {
        error_log("GMAIL MESSAGE FULL RAW RESPONSE: " . $response);
        throw new Exception("No se pudo obtener mensaje full: $messageId");
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
 }
