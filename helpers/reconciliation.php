<?php
/**
 * Parsea "Name <email@domain>" o "email@domain"
 */
function parseEmailAndName(string $raw): array
{
    $raw = trim($raw);

    // Caso: Nombre <email@dominio>
    if (preg_match('/^(.*)<(.+?)>$/', $raw, $m)) {
        return [
            'name'  => trim(trim($m[1]), '"'),
            'email' => trim($m[2]),
        ];
    }

    // Caso: solo email
    return [
        'name'  => '',
        'email' => $raw,
    ];
}

//Normalizar textos
function normalizeText(?string $value): string
{
    $value = $value ?? '';
    $value = trim($value);
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace('/\s+/', ' ', $value);
}

/**
 * Reconciliar email temporal (sent_*) contra email real de Gmail
 */
function reconcileSentTempAgainstReal(
    PDO $conn,
    int $userId,
    int $threadId,
    int $realEmailId,
    array $real
): void {

    // Ventana de 10 minutos
    $windowSeconds = 10 * 60;

    if (empty($real['internal_date'])) {
    return;
    }

    $realTs = strtotime($real['internal_date']);
    if (!$realTs) {
        return;
    }

    $fromEmail = normalizeText($real['from_email']);
    $subject   = normalizeText($real['subject']);
    $body      = normalizeText($real['body_text']);

    $stmt = $conn->prepare("
        SELECT id, sent_at_local, subject_original, body_text, from_email
        FROM emails
        WHERE user_id = ?
          AND thread_id = ?
          AND is_temporary = 1
          AND replaced_by IS NULL
          AND is_deleted = 0
        ORDER BY sent_at_local DESC
        LIMIT 5
    ");

    $stmt->execute([
        $userId,
        $threadId,
    ]);

    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $temp) {

        if (normalizeText($temp['from_email']) !== $fromEmail) {
        continue;
        }

        if (empty($temp['sent_at_local'])) {
        continue;
        }

        $tempTs = strtotime($temp['sent_at_local']);
        if (!$tempTs) {
            continue;
        }

        //ventana temporal
        if (abs($realTs - $tempTs) > $windowSeconds) {
            continue;
        }

        //subject tolerante
        if (normalizeText($temp['subject_original']) !== $subject) {
            continue;
        }

        //body tolerante
        if (normalizeText($temp['body_text']) !== $body) {
            continue;
        }

        // MATCH → reconciliar
        $upd = $conn->prepare("
            UPDATE emails
            SET is_deleted = 1,
                replaced_by = ?
            WHERE id = ?
        ");
        $upd->execute([$realEmailId, $temp['id']]);

        error_log(
            "RECONCILIATION OK temp={$temp['id']} real={$realEmailId}"
        );

        return; // reconciliamos solo uno
    }
}

/**
 * Garantiza que un email temporal no tenga adjuntos
 * y que los adjuntos solo existan en el email real
 */
function reconcileTempAttachmentsAgainstReal(
    PDO $conn,
    int $tempEmailId,
    int $realEmailId
): void {

    // 1. Eliminar cualquier adjunto ligado al email temporal
    $stmt = $conn->prepare("
        DELETE FROM email_attachments
        WHERE email_id = ?
    ");
    $stmt->execute([$tempEmailId]);

    // 2. Recalcular flag has_attachments del email real
    $stmt = $conn->prepare("
        UPDATE emails
        SET has_attachments = (
            SELECT COUNT(*) > 0
            FROM email_attachments
            WHERE email_id = ?
        )
        WHERE id = ?
    ");
    $stmt->execute([$realEmailId, $realEmailId]);

    error_log(
        "ATTACHMENT RECONCILIATION OK temp={$tempEmailId} real={$realEmailId}"
    );
}
