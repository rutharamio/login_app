<?php
require __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Redactar correo</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>

<div class="app-container">
<div class="card">

<h2>Redactar correo</h2>

<form method="post" action="send.php" enctype="multipart/form-data">

    <label>Para</label>
    <input type="email" name="to" required>

    <label>Asunto</label>
    <input type="text" name="subject" required>

    <label>Mensaje</label>
    <textarea name="message" rows="6"></textarea>

    <input
        type="file"
        name="attachments[]"
        id="fileInput"
        multiple
        hidden
    >

    <div class="actions">
        <label for="fileInput" class="btn btn-light">
            Adjuntar archivos
        </label>

        <button type="submit" class="btn btn-primary">
            Enviar correo
        </button>

        <a href="inbox.php" class="btn btn-light">
            Cancelar
        </a>
    </div>

    <p class="muted" id="fileInfo">
        No se han seleccionado archivos.
    </p>
    <ul id="filePreview"></ul>

</form>

</div>
</div>

<script>
const fileInput = document.getElementById('fileInput');
const info = document.getElementById('fileInfo');
const preview = document.getElementById('filePreview');

let filesList = [];

fileInput.addEventListener('change', () => {
    filesList = Array.from(fileInput.files);
    renderPreview();
});

function renderPreview() {
    preview.innerHTML = '';

    if (filesList.length === 0) {
        info.textContent = 'No se han seleccionado archivos.';
        updateInputFiles();
        return;
    }

    info.textContent = `Archivos seleccionados (${filesList.length}):`;

    filesList.forEach((file, index) => {
        const li = document.createElement('li');
        li.style.display = 'flex';
        li.style.justifyContent = 'space-between';
        li.style.alignItems = 'center';
        li.style.gap = '8px';

        li.innerHTML = `
            <span>${file.name} (${Math.round(file.size / 1024)} KB)</span>
            <button type="button"
                    class="btn btn-light"
                    style="padding:4px 8px"
                    onclick="removeFile(${index})">
                ✖
            </button>
        `;

        preview.appendChild(li);
    });

    updateInputFiles();
}

function removeFile(index) {
    filesList.splice(index, 1);
    renderPreview();
}

function updateInputFiles() {
    const dt = new DataTransfer();
    filesList.forEach(f => dt.items.add(f));
    fileInput.files = dt.files;
}
</script>   
</body>
</html>
