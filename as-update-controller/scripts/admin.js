(() => {
    'use strict';
    const feedback = document.getElementById('asuc-feedback');
    if (!feedback) return;
    let busy = false;
    const report = (message, error = false) => {
        feedback.textContent = message;
        feedback.className = error ? 'notice notice-error asuc-feedback' : 'notice notice-info asuc-feedback';
    };
    const call = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'asuc_action', operation, nonce: asucAdmin.nonce });
        Object.entries(values).forEach(([key, value]) => Array.isArray(value) ? value.forEach(v => body.append(`${key}[]`, v)) : body.set(key, value));
        const response = await fetch(asucAdmin.url, { method: 'POST', credentials: 'same-origin', body });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('The request was interrupted. Reload this page to review results and resume safely.'); }
        if (!result.success) throw new Error(result.data?.message || 'The action could not be completed.');
        return result.data;
    };
    const runBatch = async (job) => {
        while (job.status === 'running') {
            report(`Processing selected plugins: ${job.items.filter(i => ['success', 'failed'].includes(i.status)).length} of ${job.items.length} finished. You can return here to resume if interrupted.`);
            job = await call('step', { job: job.id });
        }
        const failures = job.items.filter(i => i.status === 'failed');
        report(failures.length ? `Finished with ${failures.length} unsuccessful updates. Reload to review each result and retry.` : 'Selected plugins completed. Reloading the verified results.', failures.length > 0);
        window.location.reload();
    };
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-check], [data-install], [data-resume], #asuc-update-selected');
        if (!button || busy) return;
        event.preventDefault();
        busy = true; button.disabled = true;
        try {
            if (button.hasAttribute('data-check')) {
                report('Checking the published catalogue…');
                const result = await call('check', { plugin_id: button.dataset.check });
                report(result.message); window.location.reload();
            } else if (button.dataset.resume) {
                const result = await call('status');
                if (!result.batch || result.batch.id !== button.dataset.resume) throw new Error('This batch has changed. Reload to see its current state.');
                await runBatch(result.batch);
            } else {
                const ids = button.dataset.install ? [button.dataset.install] : [...document.querySelectorAll('[name="asuc-selected"]:checked')].map(i => i.value);
                if (!ids.length) throw new Error('Select at least one eligible plugin update.');
                report('Preparing selected plugins…');
                await runBatch(await call('start', { ids, kind: button.dataset.kind || 'update' }));
            }
        } catch (error) { report(error.message, true); }
        finally { busy = false; button.disabled = false; }
    });
    document.getElementById('asuc-select-all')?.addEventListener('change', event => {
        document.querySelectorAll('[name="asuc-selected"]').forEach(box => { box.checked = event.target.checked; });
    });
    document.getElementById('asuc-search')?.addEventListener('input', event => {
        let visible = 0;
        document.querySelectorAll('[data-search]').forEach(card => {
            card.hidden = !card.dataset.search.includes(event.target.value.trim().toLowerCase());
            if (!card.hidden) visible++;
        });
        document.getElementById('asuc-no-results').hidden = visible > 0;
    });
})();
