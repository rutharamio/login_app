document.addEventListener('DOMContentLoaded', () => {
    
    console.log('INITIAL SYNC JS VERSION NUEVA');
    
    const btn = document.getElementById('sync-now-btn');
    const toast = document.getElementById('sync-toast');
    const wrapper = document.getElementById('sync-wrapper');

    let pollingStarted = false;

    function showToast(message) {
        toast.textContent = message;
        toast.classList.remove('hidden');

        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
    }

    function startPolling() {
        if (pollingStarted) return;
        pollingStarted = true;

        const interval = setInterval(async () => {
            try {
                const res = await fetch('/login_app/actions/sync/check_initial_sync.php');

                if (!res.ok) {
                throw new Error('HTTP ' + res.status);
                }


                const data = await res.json();

                if (data.needs_initial_sync === 0) {
                    clearInterval(interval);

                    showToast('Cargando bandeja de entrada.');

                    wrapper.classList.add('fade-out');

                    setTimeout(() => {
                        window.location.href = 'inbox.php';
                    }, 900);
                }
            } catch (e) {
                console.error('Polling error', e);
            }
        }, 5000); // cada 5s
    }

    if (btn) {

        console.log('initial-sync.js cargado');
        console.log('btn:', btn);

        btn.addEventListener('click', (e) => {
            e.preventDefault ();

            btn.disabled = true;
            btn.textContent = 'Sincronizando.';

            fetch('/login_app/actions/sync/trigger_initial_sync.php', {
                method: 'POST'
            });

            showToast('Esto puede tomar unos minutos.');
            startPolling();
        });
    }
});
