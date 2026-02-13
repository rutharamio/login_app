const fileInput = document.getElementById('replyAttachments');
const preview = document.getElementById('attachmentPreview');

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

    // Botón eliminar 
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'attachment-remove';
    removeBtn.innerHTML = 'Eliminar';
    removeBtn.onclick = () => {
        selectedFiles.splice(index, 1);
        syncFileInput();
        renderPreview();
    };

    // Contenido preview
    const content = document.createElement('div');
    content.className = 'attachment-content';

    // Imagen
    if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.className = 'attachment-img';
        content.appendChild(img);

    // PDF
    } else if (file.type === 'application/pdf') {
        const iframe = document.createElement('iframe');
        iframe.src = URL.createObjectURL(file);
        iframe.className = 'attachment-pdf';
        content.appendChild(iframe);

    // Otros archivos
    } else {
        content.textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
    }

    item.appendChild(removeBtn);
    item.appendChild(content);
    preview.appendChild(item);
});
}

// Mantener sincronizado el input file real
function syncFileInput() {
const dataTransfer = new DataTransfer();
selectedFiles.forEach(file => dataTransfer.items.add(file));
fileInput.files = dataTransfer.files;
}