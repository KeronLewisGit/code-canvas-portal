import Alpine from 'alpinejs';
import SignaturePad from 'signature_pad';

/** Line item editor for quotations and invoices. */
Alpine.data('lineItems', (initial = [], taxRate = 0) => ({
    items: initial.length ? initial.map((i) => ({ description: i.description ?? '', quantity: i.quantity ?? 1, unit_price: i.unit_price ?? 0 })) : [{ description: '', quantity: 1, unit_price: 0 }],
    tax: Number(taxRate) || 0,
    add() { this.items.push({ description: '', quantity: 1, unit_price: 0 }); },
    remove(i) { this.items.splice(i, 1); if (!this.items.length) this.add(); },
    line(i) { return (Number(i.quantity) || 0) * (Number(i.unit_price) || 0); },
    get subtotal() { return this.items.reduce((s, i) => s + this.line(i), 0); },
    get taxAmount() { return Math.round(this.subtotal * (Number(this.tax) || 0)) / 100; },
    get total() { return this.subtotal + this.taxAmount; },
    fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
}));

/** Terms settings: ordered list of heading + text clauses. */
Alpine.data('clauseList', (initial = []) => ({
    clauses: initial.map((c, i) => ({ id: i + 1, heading: c.heading ?? '', text: c.text ?? '' })),
    nextId: initial.length + 1,
    add() { this.clauses.push({ id: this.nextId++, heading: '', text: '' }); },
    remove(i) { if (confirm('Remove this clause?')) this.clauses.splice(i, 1); },
    move(i, d) { const j = i + d; if (j < 0 || j >= this.clauses.length) return; [this.clauses[i], this.clauses[j]] = [this.clauses[j], this.clauses[i]]; },
}));

/** Payment form: show the proof upload only for methods that need it. */
Alpine.data('paymentForm', (method, needsProof = {}, invoice = '', symbol = 'TT$') => ({
    method,
    needsProof,
    invoice,
    symbol,
    get proofRequired() { return !!this.needsProof[this.method]; },
    /** Picking an invoice fills in its balance and currency; the contract choice is then not needed. */
    pickInvoice(option) {
        if (option?.dataset.balance) this.$refs.amount.value = option.dataset.balance;
        if (option?.dataset.symbol) this.symbol = option.dataset.symbol;
    },
}));

/** Contract signing: draws on a canvas and posts the PNG as a data URL. */
Alpine.data('signaturePad', () => ({
    pad: null,
    empty: true,
    error: '',
    init() {
        const canvas = this.$refs.canvas;
        const resize = () => {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const data = this.pad ? this.pad.toData() : [];
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            if (this.pad) { this.pad.clear(); this.pad.fromData(data); }
        };
        this.pad = new SignaturePad(canvas, { penColor: '#1a2238', minWidth: 1, maxWidth: 2.5 });
        this.pad.addEventListener('endStroke', () => { this.empty = this.pad.isEmpty(); this.error = ''; this.$refs.data.value = this.empty ? '' : this.pad.toDataURL('image/png'); });
        resize();
        window.addEventListener('resize', resize);
    },
    clear() { this.pad.clear(); this.empty = true; this.$refs.data.value = ''; },
    mode: 'draw',
    typed: '',
    capture(event) {
        if (this.mode === 'type') {
            if (!this.typed.trim()) { this.error = 'Please type your signature.'; event.preventDefault(); return; }
            this.$refs.data.value = this.typedImage(this.typed.trim());
            return;
        }
        if (this.pad.isEmpty()) { this.error = 'Please draw your signature, or choose "Type it instead".'; event.preventDefault(); return; }
        this.$refs.data.value = this.pad.toDataURL('image/png');
    },
    /** Renders a typed signature in a handwriting-style font as a PNG, so it is stored exactly like a drawn one. */
    typedImage(text) {
        const canvas = document.createElement('canvas');
        canvas.width = 900; canvas.height = 240;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#1a2238';
        ctx.font = 'italic 84px "Segoe Script", "Brush Script MT", "Snell Roundhand", cursive';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, 30, 120, 840);
        return canvas.toDataURL('image/png');
    },
}));

/** Filter forms apply as soon as a select changes; search boxes still submit on Enter. */
document.addEventListener('change', (e) => {
    const form = e.target.closest('form[data-autosubmit]');
    if (form && e.target.tagName === 'SELECT') form.requestSubmit();
});

/**
 * Confirmation and double-submit protection for every form.
 * - data-confirm on a form or on the clicked button asks first (replaces inline confirm() handlers).
 * - After a POST form submits, its buttons are disabled so a second click cannot send a payment, email or
 *   signature twice. The clicked button's name/value is copied to a hidden field first, because disabled
 *   buttons are left out of the submission.
 */
document.addEventListener('submit', (e) => {
    const form = e.target;
    const message = e.submitter?.dataset.confirm ?? form.dataset.confirm;
    if (message && !e.defaultPrevented && !window.confirm(message)) {
        e.preventDefault();
        return;
    }
    if (e.defaultPrevented || (form.method || 'get').toLowerCase() !== 'post' || form.target === '_blank') return;
    if (form.dataset.submitting) { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    if (e.submitter?.name) {
        const keep = document.createElement('input');
        keep.type = 'hidden'; keep.name = e.submitter.name; keep.value = e.submitter.value;
        form.appendChild(keep);
    }
    setTimeout(() => form.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.disabled = true; b.setAttribute('aria-busy', 'true'); }), 0);
});
// Pages restored from the back/forward cache come back with their buttons usable again.
window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-submitting]').forEach((f) => { delete f.dataset.submitting; f.querySelectorAll('button[aria-busy]').forEach((b) => { b.disabled = false; b.removeAttribute('aria-busy'); }); });
});

/** Ties each field's hint and server error to its input for screen readers, and marks invalid inputs. */
document.querySelectorAll('[id$="-hint"], [id$="-error"]').forEach((note) => {
    const input = document.getElementById(note.id.replace(/-(hint|error)$/, ''));
    if (!input) return;
    const ids = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
    ids.add(note.id);
    input.setAttribute('aria-describedby', [...ids].join(' '));
    if (note.id.endsWith('-error')) input.setAttribute('aria-invalid', 'true');
});

window.Alpine = Alpine;
Alpine.start();
