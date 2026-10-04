(() => {
    'use strict';
    const root = document.querySelector('.asuc-wrap');
    const dialog = document.getElementById('asuc-dialog');
    if (!root || !dialog) return;
    const title = document.getElementById('asuc-dialog-title');
    const message = document.getElementById('asuc-dialog-message');
    const activity = dialog.querySelector('.spinner');
    const area = document.getElementById('asuc-progress-area');
    const progress = document.getElementById('asuc-progress');
    const current = document.getElementById('asuc-current');
    const failures = document.getElementById('asuc-failures');
    const close = document.getElementById('asuc-close');
    const retry = document.getElementById('asuc-retry');
    let busy = false, retryAction = null, opener = null, reloadOnClose = false;
    const name = id => asucAdmin.names[id] || id;
    const setBusy = value => {
        busy = value;
        close.disabled = value;
        activity.hidden = !value;
        dialog.setAttribute('aria-busy', String(value));
        progress.classList.toggle('asuc-progress-active', value);
    };
    const open = label => {
        if (!dialog.open) opener = document.activeElement;
        title.textContent = label;
        message.textContent = 'Preparing…';
        area.hidden = true;
        failures.hidden = true;
        failures.open = false;
        failures.querySelector('ul').replaceChildren();
        retry.hidden = true;
        retryAction = null;
        reloadOnClose = false;
        if (!dialog.open) dialog.showModal();
        setBusy(true);
        title.focus();
    };
    const call = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'asuc_action', operation, nonce: asucAdmin.nonce });
        Object.entries(values).forEach(([key, value]) => Array.isArray(value) ? value.forEach(v => body.append(`${key}[]`, v)) : body.set(key, value));
        const response = await fetch(asucAdmin.url, { method: 'POST', credentials: 'same-origin', body });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('The request was interrupted. Retry to recover the current operation.'); }
        if (!response.ok || !result.success) throw new Error(result.data?.message || 'The action could not be completed.');
        return result.data;
    };
    const selection = () => {
        const boxes = [...root.querySelectorAll('[name="asuc-selected"]:not(:disabled)')];
        const selected = boxes.filter(box => box.checked);
        const all = document.getElementById('asuc-select-all');
        if (all) {
            all.disabled = boxes.length === 0;
            all.checked = boxes.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
        const update = document.getElementById('asuc-update-selected');
        if (update) update.disabled = selected.length === 0;
    };
    const refresh = async () => {
        const tab = new URL(window.location.href).searchParams.get('tab') || 'installed';
        const result = await call('view', { tab });
        const page = new DOMParser().parseFromString(result.html, 'text/html');
        const view = page.getElementById('asuc-view');
        if (!view) throw new Error('Could not refresh the plugin list.');
        document.getElementById('asuc-view').replaceChildren(...view.childNodes);
        selection();
    };
    const finish = async job => {
        area.hidden = true;
        current.textContent = '';
        const failed = job.items.filter(item => item.status === 'failed');
        const count = job.items.filter(item => item.status === 'success').length;
        title.textContent = failed.length ? 'Finished with errors' : 'Complete';
        const verb = job.kind === 'install' ? 'installed' : 'updated';
        message.textContent = `${count} plugin${count === 1 ? '' : 's'} ${verb}${failed.length ? `, ${failed.length} failed` : ''}.`;
        failures.querySelector('ul').replaceChildren();
        failed.forEach(item => {
            const li = document.createElement('li');
            li.textContent = `${name(item.id)}: ${item.message || 'Update failed.'}`;
            failures.querySelector('ul').append(li);
        });
        failures.hidden = failed.length === 0;
        try { await refresh(); } catch (_) {
            message.textContent += ' Close to reload the plugin list.';
            reloadOnClose = true;
        }
        setBusy(false);
        close.focus();
    };
    const runBatch = async job => {
        area.hidden = false;
        while (job.status === 'running') {
            const done = job.items.filter(i => ['success', 'failed'].includes(i.status)).length;
            progress.max = job.items.length;
            progress.value = done;
            const item = job.items.find(i => ['pending', 'working'].includes(i.status));
            message.textContent = `${done} of ${job.items.length} completed`;
            current.textContent = item ? `${job.kind === 'install' ? 'Installing' : 'Updating'} ${name(item.id)}…` : 'Finishing…';
            job = await call('step', { job: job.id });
        }
        progress.value = progress.max;
        await finish(job);
    };
    const execute = async (label, action, recover = action) => {
        open(label);
        try { await action(); }
        catch (error) {
            setBusy(false);
            title.textContent = 'Unable to complete';
            message.textContent = error.message || 'The request failed. Please try again.';
            retryAction = () => execute(label, recover, recover);
            retry.hidden = false;
            retry.focus();
        }
        finally { setBusy(false); }
    };
    const check = async (continuation = '', full = false) => {
        let scan = continuation ? await call('scan_step', { job: continuation }) : await call('check', { full: full ? '1' : '' });
        area.hidden = false;
        while (scan.status === 'running') {
            message.textContent = scan.message;
            progress.max = Math.max(1, scan.total || 1);
            progress.value = scan.done || 0;
            scan = await call('scan_step', { job: scan.id });
        }
        await refresh();
        area.hidden = true;
        title.textContent = scan.status === 'complete' ? 'Check complete' : (scan.known_checked ? 'Plugin updates checked' : 'Check incomplete');
        message.textContent = scan.message;
        setBusy(false);
        close.focus();
    };
    const resume = async expected => {
        const result = await call('status');
        if (!result.batch?.id || result.batch.id !== expected) throw new Error('The operation has changed. Close and reload the page to review it.');
        await runBatch(result.batch);
    };
    const results = async () => {
        const result = await call('status');
        if (!result.batch?.id || result.batch.status === 'running') throw new Error('The operation has changed. Close and reload the page to review it.');
        await finish(result.batch);
    };
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-plugin-action], [data-full-check], [data-check], [data-install], [data-resume], [data-results], #asuc-update-selected');
        if (!button || busy) return;
        event.preventDefault();
        if (button.hasAttribute('data-plugin-action')) {
            const operation = button.dataset.pluginAction, id = button.dataset.pluginId;
            if (operation === 'delete' && !window.confirm(`Delete ${name(id)}? WordPress will remove its files and run its uninstall routine, which may delete saved data.`)) return;
            return void execute(`${operation === 'delete' ? 'Deleting' : operation === 'activate' ? 'Activating' : 'Deactivating'} ${name(id)}`, async () => {
                const result = await call('plugin_action', { plugin_id: id, plugin_action: operation });
                title.textContent = 'Complete'; message.textContent = result.message;
                try { await refresh(); } catch (_) {
                    message.textContent += ' Close to reload the plugin list.';
                    reloadOnClose = true;
                }
                setBusy(false); close.focus();
            });
        }
        if (button.hasAttribute('data-full-check')) return void execute('Rechecking all repositories', () => check('', true));
        if (button.hasAttribute('data-check')) return void execute('Checking for updates', check);
        if (button.hasAttribute('data-results')) return void execute('Update results', results);
        if (button.hasAttribute('data-resume')) return void execute('Updating plugins', () => resume(button.dataset.resume));
        const ids = button.dataset.install ? [button.dataset.install] : [...root.querySelectorAll('[name="asuc-selected"]:checked:not(:disabled)')].map(i => i.value);
        if (!ids.length) return;
        const kind = button.dataset.kind || 'update';
        let jobId = null, previousJobId = null, startAttempted = false;
        const start = async () => {
            message.textContent = 'Preparing plugins…';
            if (!startAttempted) previousJobId = (await call('status')).batch?.id || '';
            startAttempted = true;
            const job = await call('start', { ids, kind });
            jobId = job.id;
            await runBatch(job);
        };
        const recover = async () => {
            if (!startAttempted) return await start();
            const { batch } = await call('status');
            // A lost start response may already have created this exact batch.
            const matches = batch?.kind === kind && batch.items.length === ids.length && batch.items.every(i => ids.includes(i.id));
            if (batch?.id && (batch.id === jobId || (!jobId && matches && batch.id !== previousJobId))) await runBatch(batch);
            else if (batch?.status === 'running') throw new Error('Another operation is running. Close and use Resume updates.');
            else await start();
        };
        void execute(kind === 'install' ? 'Installing plugins' : 'Updating plugins', start, recover);
    });
    root.addEventListener('change', event => {
        if (event.target.id === 'asuc-select-all') root.querySelectorAll('[name="asuc-selected"]:not(:disabled)').forEach(box => { box.checked = event.target.checked; });
        if (event.target.id === 'asuc-select-all' || event.target.name === 'asuc-selected') selection();
    });
    retry.addEventListener('click', () => { if (!busy && retryAction) void retryAction(); });
    close.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (reloadOnClose) { window.location.reload(); return; }
        if (opener?.isConnected) opener.focus();
        else root.querySelector('[data-check]')?.focus();
    });
    selection();
    if (asucAdmin.continueScan) void execute('Checking for updates', () => check(asucAdmin.continueScan), check);
})();
