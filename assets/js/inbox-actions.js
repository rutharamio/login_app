console.log('INBOX JS NUEVO CARGADO');

const checkboxes = document.querySelectorAll('.thread-checkbox');
const selectAll = document.getElementById('select-all');
const deleteBtn = document.getElementById('bulk-delete');

function updateToolbar() {
    const anyChecked = [...checkboxes].some(cb => cb.checked);
    deleteBtn.disabled = !anyChecked;
}

checkboxes.forEach(cb => {
    cb.addEventListener('change', updateToolbar);
});

selectAll.addEventListener('change', e => {
    checkboxes.forEach(cb => cb.checked = e.target.checked);
    updateToolbar();
});

const form = document.getElementById('bulk-form');

deleteBtn.addEventListener('click', () => {
    document.getElementById('bulk-form').submit();
});

form.addEventListener('submit', e => {
    console.log('SUBMIT EJECUTADO');
});