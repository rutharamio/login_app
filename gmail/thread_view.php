<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../helpers/date.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$threadId = (int) ($_GET['id'] ?? 0);

if ($threadId <= 0) {
    die('Thread inválido');
}

/* 1) Verificar ownership del thread interno (email_threads.id) */
$stmt = $conn->prepare("
    SELECT id
    FROM email_threads
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$threadId, $userId]);
if (!$stmt->fetchColumn()) {
    die('Thread no encontrado');
}

/* 2) Obtener asunto desde emails */
$stmt = $conn->prepare("
    SELECT subject_original
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
    ORDER BY internal_date DESC
    LIMIT 1
");
$stmt->execute([$threadId, $userId]);
$subject = $stmt->fetchColumn() ?: '(Sin asunto)';

/* 3) Obtener Gmail thread id real (desde gmail_threads) */
$stmt = $conn->prepare("
    SELECT gmail_thread_id
    FROM email_threads
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$threadId, $userId]);
$gmailThreadId = $stmt->fetchColumn();

/* 4) Marcar emails como leídos */
$stmt = $conn->prepare("
    UPDATE emails
    SET is_read = 1
    WHERE thread_id = ?
      AND user_id = ?
      AND is_read = 0
");
$stmt->execute([$threadId, $userId]);

/* 5) Obtener emails del thread */
$stmt = $conn->prepare("
    SELECT *
    FROM emails
    WHERE thread_id = ?
    AND user_id = ?
    AND (is_temporary = 0 OR replaced_by IS NULL)
    ORDER BY 
        COALESCE(internal_date, sent_at_local) ASC
");
$stmt->execute([$threadId, $userId]);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ver si el email esta eliminado
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
      AND is_temporary = 0
      AND is_deleted = 0
");
$stmt->execute([$threadId, $userId]);

$isDeletedThread = ((int)$stmt->fetchColumn() === 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($subject) ?></title>
<link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<div class="thread-container">

    <div class="thread-header">
        <div>
            <a href="inbox.php" class="btn btn-light">Volver a pendientes</a>
            <div class="thread-title">
                <?= htmlspecialchars($subject) ?>
            </div>
        </div>
    </div>

    <?php foreach ($emails as $email): ?>
        <div class="email-card">
            <div class="email-meta">
                <strong><?= htmlspecialchars($email['from_email']) ?></strong>
                <?php $dateToShow = $email['internal_date'] ?? ($email['sent_at_local'] ?? null); ?>
                <?php if ($dateToShow): ?>
                · <?= formatDateHuman($dateToShow)?>
                <?php endif; ?>
            </div>

            <div class="email-body">
                <?php
                if (!empty($email['body_html'])) {
                    echo $email['body_html'];
                } else {
                    echo nl2br(htmlspecialchars($email['body_text'] ?? ''));
                }
                ?>
            </div>

            <?php if (!empty($email['has_attachments'])): ?>
                <div class="attachments">
                    <?php
                    $stmt = $conn->prepare("
                        SELECT *
                        FROM email_attachments
                        WHERE email_id = ?
                    ");
                    $stmt->execute([$email['id']]);
                    $atts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($atts as $att):
                        $isImage = str_starts_with($att['mime_type'], 'image/');
                        $isPdf   = $att['mime_type'] === 'application/pdf';
                    ?>
                        <div class="attachment-card">
                            <div class="attachment-name">
                                <?= htmlspecialchars($att['filename']) ?>
                            </div>

                            <?php if ($isImage): ?>
                                <img
                                    src="download.php?id=<?= (int)$att['id'] ?>&inline=1"
                                    class="attachment-preview-img"
                                    alt="<?= htmlspecialchars($att['filename']) ?>"
                                >
                            <?php elseif ($isPdf): ?>
                                <iframe
                                    src="download.php?id=<?= (int)$att['id'] ?>&inline=1"
                                    class="attachment-preview-pdf">
                                </iframe>
                            <?php endif; ?>

                            <a
                                href="download.php?id=<?= (int)$att['id'] ?>"
                                class="attachment-download"
                                target="_blank">
                                Descargar
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="reply-box">
        <?php if ($isDeletedThread === false): ?>
            <h3>Responder</h3>

            <?php if (!$gmailThreadId): ?>
                <p class="muted">
                    Este hilo aún no está vinculado a Gmail (no hay gmail_threads.thread_id).
                    Ejecutá <strong>Refrescar correos</strong> para completar el mapping.
                </p>
            <?php elseif (!$gmailThreadId): ?>
                <p class="muted">
                    El hilo aun no esta vinculado a Gmail.
                </p>
            <?php else: ?>
                <form method="post" action="../actions/gmail/reply.php" enctype="multipart/form-data">
                    <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                    <input type="hidden" name="gmail_thread_id" value="<?= htmlspecialchars($gmailThreadId) ?>">

                    <textarea
                        name="message"
                        rows="5"
                        class="reply-textarea"
                        placeholder="Escribe"
                    ></textarea>

                    <div id="attachmentPreview" class="attachment-preview"></div>

                    <input
                        type="file"
                        name="attachments[]"
                        id="replyAttachments"
                        multiple
                        hidden
                    >

                    <div class="reply-actions">
                        <label for="replyAttachments" class="btn btn-light">
                            Adjuntar archivos
                        </label>

                        <button type="submit" class="btn btn-primary">
                            Enviar respuesta
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($isDeletedThread === true): ?>
            <form method="post" action="../actions/gmail/thread_action.php" style="margin-bottom: 15px;">
                <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                <button
                    type="submit"
                    name="action"
                    value="restore"
                    class="btn btn-success">
                    Restaurar conversación
                </button>
            </form>
        <?php endif; ?>
    </div>

</div>

<script src="../assets/js/reply-attachments.js"></script>
</body>
</html>