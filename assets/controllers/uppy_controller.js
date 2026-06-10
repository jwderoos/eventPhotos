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
            <ul class="mt-3 space-y-1 text-sm" data-progress-list></ul>
        `;

        this.dropzone = this.element.querySelector('[data-dropzone]');
        this.fileInput = this.element.querySelector('[data-file-input]');
        this.progressList = this.element.querySelector('[data-progress-list]');

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

        this.queue = files.map((file) => ({ file, row: this.createRow(file) }));
        this.inFlight = 0;
        this.completed = 0;
        this.total = files.length;
        this.drain();
    }

    drain() {
        while (this.inFlight < CONCURRENCY && this.queue.length) {
            const job = this.queue.shift();
            this.inFlight++;
            this.upload(job).finally(() => {
                this.inFlight--;
                this.completed++;
                if (this.completed === this.total) {
                    this.refreshGrid();
                } else {
                    this.drain();
                }
            });
        }
    }

    async upload({ file, row }) {
        if (!ALLOWED_TYPES.includes(file.type)) {
            this.fail(row, 'Not a JPEG');
            return;
        }
        if (file.size > MAX_BYTES) {
            this.fail(row, 'Too large (>25 MB)');
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
                        this.done(row, 'Already uploaded');
                    } else {
                        this.done(row, 'Uploaded');
                    }
                } else {
                    this.fail(row, `HTTP ${xhr.status}`);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                this.fail(row, 'Network error');
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
        this.progressList.appendChild(li);
        return li;
    }

    setProgress(row, pct) {
        row.querySelector('progress').value = pct;
    }

    done(row, label) {
        row.querySelector('progress').value = 100;
        row.querySelector('[data-status]').textContent = label;
    }

    fail(row, reason) {
        row.querySelector('progress').classList.add('progress-error');
        row.querySelector('[data-status]').textContent = reason;
    }

    refreshGrid() {
        const frame = document.getElementById('photos-grid');
        if (!frame) return;
        const sep = this.gridFrameValue.includes('?') ? '&' : '?';
        frame.setAttribute('src', `${this.gridFrameValue}${sep}_=${Date.now()}`);
    }
}
