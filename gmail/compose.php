<?php
require __DIR__ . '/../config/session.php';
require __DIR__ .'/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: inbox.php');
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

<form method="post" action="/login_app/actions/gmail/send.php" enctype="multipart/form-data">

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

    <div id="filePreview" class="attachment-preview"></div>

    <div class="actions">
        <label for="fileInput" class="btn btn-light">
            Adjuntar archivos
        </label>

        <button type="submit" class="btn btn-primary">
            Enviar correo
        </button>
    </div>

</form>

</div>
</div>

<script>

const fileInput = document.getElementById('fileInput');
const preview = document.getElementById('filePreview');

let selectedFiles = [];

if (fileInput && preview) {
    fileInput.addEventListener('change', () => {
        selectedFiles = Array.from(fileInput.files);
        renderPreview();
    });
}

function renderPreview() {
    preview.innerHTML = '';

    selectedFiles.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = 'attachment-preview-item';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'attachment-remove';
        removeBtn.innerHTML = 'Eliminar';
        removeBtn.onclick = () => {
            selectedFiles.splice(index, 1);
            syncFileInput();
            renderPreview();
        };

        const content = document.createElement('div');
        content.className = 'attachment-content';

        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.className = 'attachment-img';
            content.appendChild(img);
        } else if (file.type === 'application/pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = URL.createObjectURL(file);
            iframe.className = 'attachment-pdf';
            content.appendChild(iframe);
        } else {
            content.textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
        }

        item.appendChild(removeBtn);
        item.appendChild(content);
        preview.appendChild(item);
    });
}

function syncFileInput() {
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    fileInput.files = dataTransfer.files;
}

</script>   
</body>
</html>
