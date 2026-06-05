const CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB

document.addEventListener('alpine:init', () => {
    Alpine.data('uploader', () => ({
        files: [],
        dragging: false,
        uploading: false,
        done: false,
        progress: 0,
        statusText: '',
        shareUrl: '',
        copied: false,
        expireDays: 30,
        password: '',
        isFolder: false,

        get totalSize() {
            return this.files.reduce((sum, f) => sum + f.size, 0);
        },

        onFileSelect(event) {
            this.files = Array.from(event.target.files);
            this.isFolder = false;
        },

        onDrop(event) {
            this.dragging = false;
            const items = event.dataTransfer.items;
            if (!items) return;

            const filePromises = [];
            for (const item of items) {
                const entry = item.webkitGetAsEntry?.();
                if (entry?.isDirectory) {
                    this.isFolder = true;
                    filePromises.push(this._readDirectory(entry));
                } else if (entry?.isFile) {
                    filePromises.push(new Promise(r => entry.file(f => r([{ file: f, path: f.name }]))));
                }
            }

            Promise.all(filePromises).then(results => {
                const flat = results.flat();
                this.files = flat.map(r => Object.assign(r.file, { relativePath: r.path }));
            });
        },

        _readDirectory(dirEntry, basePath = '') {
            return new Promise(resolve => {
                const reader = dirEntry.createReader();
                const results = [];
                const read = () => {
                    reader.readEntries(entries => {
                        if (!entries.length) return resolve(results);
                        const promises = entries.map(entry => {
                            const path = basePath ? `${basePath}/${entry.name}` : entry.name;
                            if (entry.isDirectory) return this._readDirectory(entry, path);
                            return new Promise(r => entry.file(f => r([{ file: f, path }])));
                        });
                        Promise.all(promises).then(res => { results.push(...res.flat()); read(); });
                    });
                };
                read();
            });
        },

        reset() {
            this.files = [];
            this.uploading = false;
            this.done = false;
            this.progress = 0;
            this.statusText = '';
            this.shareUrl = '';
            this.copied = false;
            this.isFolder = false;
        },

        getMimeIcon(mime = '') {
            if (mime.startsWith('image/'))  return 'file-image';
            if (mime.startsWith('video/'))  return 'file-video';
            if (mime.startsWith('audio/'))  return 'file-audio';
            if (mime.includes('zip') || mime.includes('tar') || mime.includes('rar') || mime.includes('7z')) return 'archive';
            if (mime.includes('pdf'))       return 'file-text';
            if (mime.startsWith('text/'))   return 'file-text';
            if (mime.includes('json') || mime.includes('javascript') || mime.includes('html') || mime.includes('css')) return 'file-code';
            return 'file';
        },

        async startUpload() {
            if (!this.files.length) return;
            if (this.isFolder || this.files.length > 1) {
                await this._uploadFolder();
            } else {
                await this._uploadSingleFile(this.files[0]);
            }
        },

        async _uploadSingleFile(file) {
            this.uploading = true;
            this.progress = 0;

            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            const mime = file.type || 'application/octet-stream';

            this.statusText = 'Inicializuji upload…';
            const initRes = await fetch('/upload/init', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filename: file.name, mimeType: mime }),
            });
            const { uploadId, minioKey } = await initRes.json();

            const parts = [];
            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end   = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                this.statusText = `Nahrávám část ${i + 1} / ${totalChunks}…`;

                const form = new FormData();
                form.append('uploadId',   uploadId);
                form.append('minioKey',   minioKey);
                form.append('partNumber', String(i + 1));
                form.append('chunk',      chunk, file.name);

                const chunkRes = await fetch('/upload/chunk', { method: 'POST', body: form });
                const { etag, partNumber } = await chunkRes.json();
                parts.push({ PartNumber: partNumber, ETag: etag });

                this.progress = Math.round(((i + 1) / totalChunks) * 90);
            }

            this.statusText = 'Dokončuji…';
            const completeRes = await fetch('/upload/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    uploadId,
                    minioKey,
                    parts,
                    filename:   file.name,
                    mimeType:   mime,
                    size:       file.size,
                    expireDays: parseInt(this.expireDays),
                    password:   this.password || null,
                }),
            });

            const { shareUrl } = await completeRes.json();
            this.shareUrl  = window.location.origin + shareUrl;
            this.progress  = 100;
            this.uploading = false;
            this.done      = true;
        },

        async _uploadFolder() {
            this.uploading = true;
            this.progress  = 10;
            this.statusText = 'Odesílám soubory ke zpracování…';

            const form = new FormData();
            for (let i = 0; i < this.files.length; i++) {
                const f = this.files[i];
                form.append('files[]', f, f.name);
                form.append('names[]', f.relativePath || f.name);
            }
            form.append('archiveName', 'archiv.zip');
            form.append('expireDays',  String(this.expireDays));
            if (this.password) form.append('password', this.password);

            this.progress = 50;
            const res = await fetch('/upload/folder', { method: 'POST', body: form });
            const { shareUrl } = await res.json();

            this.shareUrl  = window.location.origin + shareUrl;
            this.progress  = 100;
            this.uploading = false;
            this.done      = true;
        },

        async copyLink() {
            await navigator.clipboard.writeText(this.shareUrl);
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },

        formatBytes(bytes) {
            if (bytes < 1024)          return bytes + ' B';
            if (bytes < 1_048_576)     return (bytes / 1024).toFixed(1) + ' KB';
            if (bytes < 1_073_741_824) return (bytes / 1_048_576).toFixed(1) + ' MB';
            return (bytes / 1_073_741_824).toFixed(2) + ' GB';
        },
    }));
});
