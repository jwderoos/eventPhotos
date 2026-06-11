import { Controller } from '@hotwired/stimulus';

const MAX_BYTES = 25 * 1024 * 1024;
const CONCURRENCY = 3;
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg'];

export default class extends Controller {
    static values = {
        endpoint: String,
        gridFrame: String,
    };

    connect() {
        this.element.innerHTML = `
            <div class="border-2 border-dashed border-base-300 rounded-box p-6 text-center"
                 data-dropzone>
                <p class="text-base-content/70 mb-2">Drag JPEGs here, or</p>
                <label class="btn btn-primary btn-sm">
                    Choose files
                    <input type="file" multiple accept="image/jpeg,.jpg,.jpeg" class="hidden" data-file-input>
                </label>
                <p class="text-xs text-base-content/50 mt-2">JPEG only, up to 25 MB each</p>
            </div>
            <div class="mt-3 max-h-64 overflow-y-auto border border-base-300 rounded-box p-3 hidden"
                 data-queue-panel>
                <section data-section="uploading" class="hidden mb-2">
                    <h3 class="text-xs font-semibold text-base-content/60 mb-1">Uploading · <span data-count>0</span></h3>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
                <section data-section="queued" class="hidden mb-2">
                    <h3 class="text-xs font-semibold text-base-content/60 mb-1">Queued · <span data-count>0</span></h3>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
                <section data-section="done" class="hidden">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-xs font-semibold text-base-content/60">Done · <span data-count>0</span></h3>
                        <button type="button"
                                class="btn btn-ghost btn-xs"
                                data-action="click->photo-uploader#clearDone">Clear done</button>
                    </div>
                    <ul class="space-y-1 text-sm" data-list></ul>
                </section>
            </div>
        `;

        this.dropzone   = this.element.querySelector('[data-dropzone]');
        this.fileInput  = this.element.querySelector('[data-file-input]');
        this.queuePanel = this.element.querySelector('[data-queue-panel]');
        this.sections   = {
            uploading: this.element.querySelector('[data-section="uploading"]'),
            queued:    this.element.querySelector('[data-section="queued"]'),
            done:      this.element.querySelector('[data-section="done"]'),
        };

        this.queue    = [];
        this.inFlight = 0;

        this.fileInput.addEventListener('change', (e) => this.handleFiles(e.target.files));

        ['dragenter', 'dragover'].forEach((evt) =>
            this.dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                this.dropzone.classList.add('border-primary');
            })
        );
        ['dragleave', 'drop'].forEach((evt) =>
            this.dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                this.dropzone.classList.remove('border-primary');
            })
        );
        this.dropzone.addEventListener('drop', (e) => this.handleFiles(e.dataTransfer.files));
    }

    handleFiles(fileList) {
        const files = Array.from(fileList);
        if (!files.length) return;

        this.queuePanel.classList.remove('hidden');

        for (const file of files) {
            const job = { file, row: this.createRow(file), state: null };
            this.moveTo(job, 'queued');
            this.queue.push(job);
        }
        this.drain();
    }

    drain() {
        while (this.inFlight < CONCURRENCY && this.queue.length) {
            const job = this.queue.shift();
            this.inFlight++;
            this.moveTo(job, 'uploading');
            this.upload(job).finally(() => {
                this.inFlight--;
                this.drain();
            });
        }
    }

    async upload(job) {
        const { file, row } = job;

        if (!ALLOWED_TYPES.includes(file.type)) {
            this.fail(job, 'Not a JPEG');
            return;
        }
        if (file.size > MAX_BYTES) {
            this.fail(job, 'Too large (>25 MB)');
            return;
        }

        const form = new FormData();
        form.append('file', file);

        await new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.endpointValue);
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    this.setProgress(row, pct);
                }
            });
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    let body = {};
                    try { body = JSON.parse(xhr.responseText); } catch (_) { /* noop */ }
                    if (body.status === 'duplicate') {
                        this.done(job, 'Already uploaded');
                    } else {
                        this.done(job, 'Uploaded');
                        if (body.rowHtml) {
                            this.prependRow(body.rowHtml);
                        }
                    }
                } else {
                    this.fail(job, `HTTP ${xhr.status}`);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                this.fail(job, 'Network error');
                resolve();
            });
            xhr.send(form);
        });
    }

    createRow(file) {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-2';
        li.innerHTML = `
            <span class="truncate flex-1">${file.name}</span>
            <progress class="progress progress-primary w-24" value="0" max="100"></progress>
            <span class="text-xs text-base-content/60 w-24 text-right" data-status></span>
        `;
        return li;
    }

    moveTo(job, state) {
        const target = this.sections[state];
        target.querySelector('[data-list]').appendChild(job.row);
        job.state = state;
        this.refreshSections();
    }

    refreshSections() {
        let total = 0;
        for (const [name, section] of Object.entries(this.sections)) {
            const list  = section.querySelector('[data-list]');
            const count = section.querySelector('[data-count]');
            count.textContent = String(list.children.length);
            section.classList.toggle('hidden', list.children.length === 0);
            total += list.children.length;
        }
        this.queuePanel.classList.toggle('hidden', total === 0);
    }

    setProgress(row, pct) {
        row.querySelector('progress').value = pct;
    }

    done(job, label) {
        job.row.querySelector('progress').value = 100;
        job.row.querySelector('[data-status]').textContent = label;
        this.moveTo(job, 'done');
    }

    fail(job, reason) {
        job.row.querySelector('progress').classList.add('progress-error');
        job.row.querySelector('[data-status]').textContent = reason;
        this.moveTo(job, 'done');
    }

    clearDone() {
        const list = this.sections.done.querySelector('[data-list]');
        list.innerHTML = '';
        this.refreshSections();
    }

    prependRow(html) {
        const frame = document.getElementById('photos-grid');
        if (!frame) return;
        // On pages past 1 we don't inject — a server refresh will reflect
        // reality eventually via the poller / a navigation back to page 1.
        const src = frame.getAttribute('src') || '';
        const pageMatch = src.match(/[?&]page=(\d+)/);
        const onLaterPage = pageMatch && pageMatch[1] !== '1';

        const tbody = onLaterPage ? null : frame.querySelector('table tbody');
        if (tbody) {
            const wrapper = document.createElement('tbody');
            wrapper.innerHTML = html.trim();
            const tr = wrapper.firstElementChild;
            if (tr) {
                tbody.prepend(tr);
                frame.dispatchEvent(new CustomEvent('photos:row-added', { bubbles: false }));
            }
            return;
        }

        // No tbody (empty state or lazy frame still loading) — refresh the
        // frame so the just-uploaded row appears via a fresh server render.
        // Debounced so a bulk upload doesn't trigger N refreshes.
        this.scheduleFrameRefresh(frame);
    }

    scheduleFrameRefresh(frame) {
        if (this.refreshTimer) return;
        this.refreshTimer = setTimeout(() => {
            this.refreshTimer = null;
            const base = frame.getAttribute('src') || '';
            if (!base) return;
            const [path, query = ''] = base.split('?');
            const params = new URLSearchParams(query);
            params.set('_', String(Date.now()));
            frame.setAttribute('src', `${path}?${params.toString()}`);
        }, 200);
    }
}
