let incrementalPollingStarted = false;

function showToast(message) {
    const toast = document.getElementById('incremental-toast');
    if (!toast) return;

    toast.textContent = message;
    toast.classList.remove('hidden');

    requestAnimationFrame(() => {
        toast.classList.add('show');
    });
}

function startIncrementalPolling() {
    if (incrementalPollingStarted) return;
    incrementalPollingStarted = true;

    console.log('[INCREMENTAL] polling iniciado');

    let attempts = 0;
    let sawRunning = false;
    const MAX_ATTEMPTS = 6; // ~18 segundos

    const interval = setInterval(async () => {
        attempts++;

        try {
            const res = await fetch('/login_app/actions/check_incremental_sync.php');
            if (!res.ok) throw new Error(res.status);

            const data = await res.json();

            if (data.running === 1) {
                sawRunning = true;
            }

            // Caso normal: hubo sync y terminó
            if (sawRunning && data.running === 0) {
                clearInterval(interval);
                finishReload();
            }

            // Caso rápido: nunca llegó a correr
            if (!sawRunning && attempts >= 2 && data.running === 0) {
                clearInterval(interval);
                finishReload();
            }

            // Failsafe
            if (attempts >= MAX_ATTEMPTS) {
                clearInterval(interval);
                finishReload();
            }

        } catch (e) {
            console.error('[INCREMENTAL] polling error', e);
            clearInterval(interval);
            finishReload();
        }
    }, 3000);
}

function finishReload() {
    showToast('Actualizando bandeja');

    setTimeout(() => {
        window.location.reload();
    }, 800);
}

document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('sync-incremental-btn');
    if (!btn) return;

    btn.addEventListener('click', async (e) => {
        e.preventDefault();

        if (incrementalPollingStarted) return;

        btn.disabled = true;
        btn.textContent = 'Refrescando';

        showToast('Buscando');

        try {
            await fetch('/login_app/gmail/sync_incremental.php', {
                method: 'POST'
            });
        } catch (e) {
            console.error('[INCREMENTAL] error disparando sync', e);
        }

        startIncrementalPolling();
    });
});
