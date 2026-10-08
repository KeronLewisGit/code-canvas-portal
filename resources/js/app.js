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
Alpine.data('paymentForm', (method, needsProof = {}) => ({
    method,
    needsProof,
    get proofRequired() { return !!this.needsProof[this.method]; },
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
    capture(event) {
        if (this.pad.isEmpty()) { this.error = 'Please draw your signature.'; event.preventDefault(); return; }
        this.$refs.data.value = this.pad.toDataURL('image/png');
    },
}));

/** Filter forms apply as soon as a select changes; search boxes still submit on Enter. */
document.addEventListener('change', (e) => {
    const form = e.target.closest('form[data-autosubmit]');
    if (form && e.target.tagName === 'SELECT') form.requestSubmit();
});

window.Alpine = Alpine;
Alpine.start();
