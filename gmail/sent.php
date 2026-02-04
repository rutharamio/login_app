<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        id,
        thread_id,
        subject_limpio,
        snippet,
        to_email,
        internal_date,
        has_attachments
    FROM emails
    WHERE user_id = ?
      AND is_sent = 1
      AND is_deleted = 0
    ORDER BY internal_date DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
h2>Enviados</h2>

<?php if (!$emails): ?>
    <p>No has enviado correos aun.</p>
<?php endif; ?>

<?php foreach ($emails as $mail): ?>
    <div class="mail-row">
        <strong>Para:</strong> <?= htmlspecialchars($mail['to_email']) ?><br>
        <strong>Asunto:</strong> <?= htmlspecialchars($mail['subject_limpio']) ?><br>
        <small><?= $mail['internal_date'] ?></small>

        <?php if ($mail['has_attachments']): ?>
            Adjunto
        <?php endif; ?>

        <a href="thread_view.php?id=<?= $mail['thread_id'] ?>">Ver conversación</a>
        <hr>
    </div>
<?php endforeach; ?>