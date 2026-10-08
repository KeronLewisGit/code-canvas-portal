import { Previewer } from 'pagedjs';

/** Lay the document source out into Letter pages. Falls back to the plain flow if anything fails. */
window.addEventListener('DOMContentLoaded', async () => {
    const source = document.getElementById('doc-source');
    const stage = document.getElementById('doc-stage');
    const status = document.getElementById('doc-pages');
    if (!source || !stage) return;

    const sheets = [stage.dataset.css];
    const inline = document.getElementById('doc-page-css');
    if (inline) sheets.push({ 'page.css': inline.textContent });

    try {
        const flow = await new Previewer().preview(source.innerHTML, sheets, stage);
        source.remove();
        if (status) status.textContent = `Letter, ${flow.total} page${flow.total === 1 ? '' : 's'}`;
        document.body.classList.add('doc-ready');
    } catch (e) {
        console.error('Pagination failed, showing the plain document', e);
        stage.innerHTML = source.innerHTML;
        source.remove();
    }
});
