<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../helpers/date.php';

// Validar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userId = $_SESSION['user_id'];

$view = $_GET['view'] ?? 'unread';

$allowedViews = ['unread', 'read', 'archived', 'deleted'];
$view = in_array($view, $allowedViews, true) ? $view : 'unread';

$where = '';
$having = '';

if ($view === 'deleted') {
    $where = 'AND e.is_deleted = 1';
} else {
    $where = 'AND e.is_deleted = 0';

    if ($view === 'unread') {
        $having = 'HAVING unread_count > 0';
    } elseif ($view === 'read') {
        $having = 'HAVING unread_count = 0';
    }
}

$sql = "
SELECT
    t.id AS thread_db_id,
    COUNT(e.id) AS total_messages,
    MAX(e.internal_date) AS last_date,
    SUBSTRING_INDEX(
        GROUP_CONCAT(e.subject_original ORDER BY e.internal_date DESC),
        ',', 1
    ) AS subject,
    SUBSTRING_INDEX(
        GROUP_CONCAT(e.snippet ORDER BY e.internal_date DESC),
        ',', 1
    ) AS snippet,
    SUBSTRING_INDEX(
        GROUP_CONCAT(e.from_email ORDER BY e.internal_date DESC),
        ',', 1
    ) AS from_email,
    SUM(CASE WHEN e.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
FROM email_threads t
JOIN emails e ON e.thread_id = t.id
WHERE t.user_id = ?
AND (e.is_temporary = 0 OR e.replaced_by IS NULL)
$where
GROUP BY t.id
$having
ORDER BY last_date DESC
LIMIT 20
";

$stmt = $conn->prepare($sql);
$stmt->execute([$userId]);
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT needs_initial_sync
    FROM google_gmail_tokens
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$needsInitial = (int) $stmt->fetchColumn();

?>

<?php if ($needsInitial === 1): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bandeja de entrada</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>

<div class="top-bar">
    <h1>Bandeja de entrada</h1>
</div>

<div class="sync-card">

    <div class="sync-wrapper" id="sync-wrapper">

        <p class="muted sync-description">
            Tu correo todavía se está preparando. Para poder continuar es necesario realizar la sincronización inicial.
        </p>

        <form method="post" action="actions/trigger_initial_sync.php" id="initial-sync-form">
            <button type="submit" class="btn btn-primary" id="sync-now-btn">
                Sincronizar ahora
            </button>
        </form>

    </div>
</div>

<div id="sync-toast" class="sync-toast hidden"></div>

<script src="/login_app/assets/js/initial-sync.js?v=<?= time() ?>"></script> 
<?php exit; ?>
<?php endif; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bandeja de entrada</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<div id="incremental-toast" class="sync-toast hidden"></div>
<div class="top-bar">
    <h1>Bandeja de entrada</h1>

<div class="header-bar">
    <div class="sidebar">
    <a href="inbox.php?view=unread">Pendientes</a>
    <a href="inbox.php?view=read">Leídos</a>
    <a href="inbox.php?view=deleted">Eliminados</a>
    </div>

    <a href="../dashboard.php" class="btn btn-light">Pestaña principal</a>

    <!-- <form method="post" action="sync_incremental.php" style="display:inline;"> -->
        <button id= 'sync-incremental-btn' type="button" class="btn btn-primary">
            Refrescar correos
        </button>
    <!-- </form> -->

    <form method="post" action="compose.php" style="display:inline;">
        <button type="submit" class="btn btn-primary">
            Redactar correo
        </button>
    </form>
</div>
</div>

<form id="bulk-form" method="post" action="bulk_action.php">
<?php if ($view === 'unread' || $view === 'read'): ?> 
    <div class="inbox-toolbar">
        <label class="select-all">
            <input type="checkbox" id="select-all">
            Seleccionar todos
        </label>

        <input type="hidden" name="action" value="delete">

        <div class="inbox-actions">
        <button
            id="bulk-delete"
            type="submit"
            class="btn btn-danger"
            disabled>
            Eliminar
        </button>
        </div>
    </div>
<?php endif; ?>
<hr>

<?php if (empty($threads)): ?>
    <p>No hay conversaciones.</p>
<?php endif; ?>

<?php foreach ($threads as $t): ?>
<div class="thread-row <?= $t['unread_count'] > 0 ? 'unread' : 'read' ?>">
<?php if ($view === 'unread' || $view === 'read') : ?>
    <!-- Checkbox -->
    <div class="thread-col checkbox-col">
        <input
        type="checkbox"
        class="thread-checkbox"
        name="thread_ids[]"
        value="<?= $t['thread_db_id'] ?>"
        onclick="event.stopPropagation()"
        >
    </div>
<?php endif; ?>
    <!-- Clickable content -->
    <a href="thread_view.php?id=<?= $t['thread_db_id'] ?>" class="thread-main">

        <div class="thread-col from-col">
            <?= htmlspecialchars($t['from_email']) ?>
        </div>

        <div class="thread-col content-col">
            <div class="thread-subject">
                <?= htmlspecialchars($t['subject']) ?>
            </div>
            <div class="thread-snippet">
                <?= htmlspecialchars($t['snippet']) ?>
            </div>
        </div>

        <div class="thread-date">
            <?= formatDateHuman($t['last_date']) ?>
        </div>

    </a>
</div>
<?php endforeach; ?>
</form>

<?php if ($view === 'deleted'): ?>
    <form method="post" action="thread_action.php" style="margin: 10px 0;">
        <button
            type="submit"
            name="action"
            value="empty_trash"
            class="btn btn-danger"
            onclick="return confirm('¿Vaciar definitivamente la papelera? Esta acción no se puede deshacer.')">
            Vaciar papelera
        </button>
    </form>
<?php endif; ?>

<script src="/login_app/assets/js/incremental-sync.js?v=<?= time() ?>"></script>
<script src="../assets/js/inbox-actions.js?v=<?= time() ?>"></script>
</body>
</html>