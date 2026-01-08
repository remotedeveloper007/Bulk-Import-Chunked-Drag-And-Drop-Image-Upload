@extends('app')

@section('content')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight text-center">
        📦 Product Import & Image Upload
    </h2>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <!-- CSV IMPORT -->
                    <div class="row justify-content-center">
                        <div class="col-md-6 col-lg-6 mb-4">
                            <div class="card shadow-sm rounded-3">
                                <form id="csvForm" method="POST" action="/import/products">
                                <div class="card-header text-center">
                                    <h5>📝 Bulk CSV Import</h5>
                                </div>
                                <div class="card-body p-4">    
                                    @csrf
                                    <input type="file" name="csv" class="form-control mt-4 mb-5" accept=".csv" required>
                                    <div class="alert alert-info small mb-0">
                                        <strong>CSV Format:</strong><br>
                                        • Required: <code>sku,name,price</code><br>
                                        • Optional: <code>image</code> (filename to link uploaded images)
                                    </div>
                                </div>
                                <div class="card-footer text-center">
                                    <button type="submit" class="btn btn-primary">Import Products</button>
                                </div>
                                </form>
                            </div>
                        </div>

                        <!-- BATCH IMAGE UPLOAD -->
                        <div class="col-md-6 col-lg-6 mb-4">
                            <div class="card shadow-sm rounded-3">
                                <div class="card-header text-center">
                                    <h5>🖼️ Batch Image Upload</h5>
                                </div>
                                <div class="card-body p-4">
                                    <div id="dropZone" class="border-3 border-dashed p-5 text-center" style="border-color: #ddd; border-radius: 8px; cursor: pointer;">
                                        <p class="mb-2">📂 Drag & drop multiple images here</p>
                                        <p class="text-muted small">or click to select (supports 100s of images)</p>
                                        <input type="file" id="imageInput" accept="image/*" multiple style="display: none;">
                                    </div>
                                    
                                    <!-- Overall Progress -->
                                    <div id="uploadProgress" class="mt-3" style="display: none;">
                                        <div class="progress">
                                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                        </div>
                                        <p id="progressText" class="text-center mt-2 small">Uploading: 0 / 0 images</p>
                                    </div>

                                    <!-- Individual File Progress -->
                                    <div id="fileList" class="mt-3" style="max-height: 200px; overflow-y: auto;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RESULTS -->
                    <div class="row justify-content-center mt-4">
                        <div class="col-12">
                            <div id="csvResult" class="bg-light p-3 rounded border" style="max-height: 420px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const CHUNK_SIZE = 1024 * 1024; // 1MB
    let MAX_CONCURRENT_UPLOADS = 6;  // Adaptive based on batch size
    const CHUNK_RETRY_ATTEMPTS = 5;  // Increased from 3 to 5 for better reliability
    const CHUNK_RETRY_DELAY = 1500;  // Increased to 1.5s for better server recovery time

    const dropZone = document.getElementById('dropZone');
    const imageInput = document.getElementById('imageInput');
    const uploadProgress = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const fileList = document.getElementById('fileList');
    const csvResult = document.getElementById('csvResult');
    const csvForm = document.getElementById('csvForm');

    let uploadQueue = [];
    let uploadedCount = 0;
    let totalFiles = 0;
    let activeUploads = 0;
    let failedUploads = [];  // Track failed uploads for retry
    let successfulUploads = [];  // Track successful server-confirmed uploads

    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        document.body.addEventListener(eventName, evt => {
            evt.preventDefault();
            evt.stopPropagation();
        });
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.style.borderColor = '#0d6efd';
            dropZone.style.backgroundColor = '#f8f9fa';
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.style.borderColor = '#ddd';
            dropZone.style.backgroundColor = 'white';
        });
    });

    dropZone.addEventListener('drop', evt => {
        const files = evt.dataTransfer?.files || [];
        if (files.length) handleFiles(files);
    });

    dropZone.addEventListener('click', () => imageInput.click());
    imageInput.addEventListener('change', evt => {
        if (evt.target.files?.length) handleFiles(evt.target.files);
    });

    function handleFiles(files) {
        const imageFiles = Array.from(files).filter(file => file.type.startsWith('image/'));

        if (!imageFiles.length) {
            alert('Please select at least one image file.');
            return;
        }

        if (imageFiles.length !== files.length) {
            alert(`${files.length - imageFiles.length} non-image files were skipped.`);
        }

        // Adaptive concurrency: reduce for large batches to ensure stability
        if (imageFiles.length >= 100) {
            MAX_CONCURRENT_UPLOADS = 3;  // Very conservative for 100+ files (max reliability)
        } else if (imageFiles.length > 50) {
            MAX_CONCURRENT_UPLOADS = 4;  // Conservative for 50-99 files
        } else if (imageFiles.length > 20) {
            MAX_CONCURRENT_UPLOADS = 5;
        } else {
            MAX_CONCURRENT_UPLOADS = 6;
        }

        uploadQueue = imageFiles.map((file, index) => ({ file, index }));
        uploadedCount = 0;
        totalFiles = uploadQueue.length;
        activeUploads = 0;
        failedUploads = [];  // Reset failed uploads on new batch
        successfulUploads = [];  // Reset successful uploads on new batch

        uploadProgress.style.display = 'block';
        fileList.innerHTML = '';
        progressBar.classList.add('progress-bar-animated');
        progressBar.classList.remove('bg-success');
        updateOverallProgress();

        uploadQueue.forEach(({ file, index }) => {
            const row = document.createElement('div');
            row.id = `file-${index}`;
            row.className = 'small p-2 border-bottom';
            row.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-truncate" style="max-width: 70%;" title="${file.name}">${file.name}</span>
                    <span class="badge bg-secondary" id="status-${index}">Queued</span>
                </div>
            `;
            fileList.appendChild(row);
        });

        processUploadQueue();
    }

    function updateOverallProgress() {
        const percent = totalFiles > 0 ? Math.round((uploadedCount / totalFiles) * 100) : 0;
        progressBar.style.width = `${percent}%`;
        progressText.textContent = `Uploading: ${uploadedCount} / ${totalFiles} images (${percent}%)`;
    }

    function processUploadQueue() {
        while (uploadQueue.length > 0 && activeUploads < MAX_CONCURRENT_UPLOADS) {
            const next = uploadQueue.shift();
            if (!next) break;
            activeUploads++;
            uploadFile(next).finally(() => {
                activeUploads--;
                processUploadQueue();
            });
        }

        if (!uploadQueue.length && activeUploads === 0 && uploadedCount === totalFiles && totalFiles > 0) {
            const failedCount = failedUploads.length;
            const successCount = successfulUploads.length;
            
            if (failedCount > 0) {
                progressText.textContent = `⚠️ ${successCount}/${totalFiles} uploaded (${failedCount} failed)`;
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-warning');

                csvResult.innerHTML = `
                    <div class="alert alert-warning mb-0">
                        <h5>⚠️ Upload Completed with ${failedCount} Error${failedCount > 1 ? 's' : ''}</h5>
                        <p class="mb-2"><strong>${successCount} of ${totalFiles}</strong> images uploaded successfully.</p>
                        <p class="small mb-2"><strong>Failed files (${failedCount}):</strong> ${failedUploads.map(f => f.file.name).join(', ')}</p>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-danger" onclick="retryFailedUploads()">
                                🔁 Retry ${failedCount} Failed Upload${failedCount > 1 ? 's' : ''}
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="autoRetryUntilSuccess()">
                                🔄 Auto-Retry Until All Succeed
                            </button>
                        </div>
                    </div>
                `;
            } else {
                progressText.textContent = `✅ All ${successCount} images uploaded successfully!`;
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-success');

                csvResult.innerHTML = `
                    <div class="alert alert-success mb-0">
                        <h5>✅ Batch Upload Complete</h5>
                        <p class="mb-1"><strong>${successCount} images</strong> uploaded and queued for variant generation (256px, 512px, 1024px).</p>
                        <p class="small mb-0">Now import your CSV with an <code>image</code> column to auto-link these uploads to products.</p>
                    </div>
                `;
            }
        }
    }

    async function uploadFile({ file, index }) {
        const statusBadge = document.getElementById(`status-${index}`);
        if (!statusBadge) return;
        statusBadge.textContent = 'Uploading…';
        statusBadge.className = 'badge bg-info';

        let uploadCompleted = false;  // Track if upload fully completed on server

        try {
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE) || 1;
            const checksum = await calculateChecksum(file);

            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                const formData = new FormData();
                formData.append('chunk', chunk);
                formData.append('chunk_index', i);
                formData.append('total_chunks', totalChunks);
                formData.append('original_name', file.name);
                formData.append('checksum', checksum);

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                
                // Upload with retry logic
                let uploadSuccess = false;
                for (let attempt = 0; attempt < CHUNK_RETRY_ATTEMPTS; attempt++) {
                    try {
                        const res = await fetch('/upload/chunk', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-CSRF-TOKEN': csrfToken },
                            signal: AbortSignal.timeout(30000) // 30s timeout
                        });

                        if (!res.ok) {
                            throw new Error(`HTTP ${res.status}`);
                        }

                        const data = await res.json();
                        if (data.status === 'completed') {
                            statusBadge.textContent = 'Completed ✓';
                            statusBadge.className = 'badge bg-success';
                            uploadCompleted = true;  // Server confirmed all chunks received
                        }
                        uploadSuccess = true;
                        break; // Success, exit retry loop
                    } catch (error) {
                        if (attempt < CHUNK_RETRY_ATTEMPTS - 1) {
                            // Exponential backoff: 1.5s, 3s, 6s, 12s, 24s
                            const delay = CHUNK_RETRY_DELAY * Math.pow(2, attempt);
                            await new Promise(r => setTimeout(r, delay));
                        } else {
                            throw error; // All retries exhausted
                        }
                    }
                }

                if (!uploadSuccess) {
                    throw new Error(`Chunk ${i} failed after ${CHUNK_RETRY_ATTEMPTS} attempts`);
                }
            }

            // Only count as successful if server confirmed completion
            if (uploadCompleted) {
                uploadedCount++;
                successfulUploads.push({ file, index });
            } else {
                // Chunks uploaded but upload never reached 'completed' status
                console.warn(`[${file.name}] All chunks sent but upload not marked completed by server`);
                statusBadge.textContent = 'Incomplete ⚠';
                statusBadge.className = 'badge bg-warning';
                failedUploads.push({ file, index });
                uploadedCount++;
            }
            updateOverallProgress();
        } catch (error) {
            console.error(`[${file.name}] Upload failed after ${CHUNK_RETRY_ATTEMPTS} attempts:`, error);
            statusBadge.textContent = 'Failed ✗';
            statusBadge.className = 'badge bg-danger';
            failedUploads.push({ file, index });  // Track for retry
            uploadedCount++;
            updateOverallProgress();
        }
    }

    // Add retry function for failed uploads
    window.retryFailedUploads = function() {
        if (failedUploads.length === 0) return;
        
        uploadQueue = failedUploads.map(f => f);
        failedUploads = [];
        uploadedCount -= uploadQueue.length;  // Subtract from count to re-add on success
        totalFiles = uploadedCount + uploadQueue.length;
        
        progressBar.classList.add('progress-bar-animated');
        progressBar.classList.remove('bg-warning', 'bg-success');
        csvResult.innerHTML = '<div class="alert alert-info mb-0">Retrying failed uploads...</div>';
        
        processUploadQueue();
    };

    // Auto-retry until all uploads succeed (up to 10 attempts)
    let autoRetryAttempts = 0;
    window.autoRetryUntilSuccess = async function() {
        if (failedUploads.length === 0 || autoRetryAttempts >= 10) {
            if (autoRetryAttempts >= 10) {
                csvResult.innerHTML = `
                    <div class="alert alert-danger mb-0">
                        <h5>❌ Auto-Retry Failed</h5>
                        <p>Unable to upload ${failedUploads.length} file(s) after 10 retry attempts. Please check your network connection or try uploading these files individually.</p>
                    </div>
                `;
            }
            autoRetryAttempts = 0;
            return;
        }
        
        autoRetryAttempts++;
        csvResult.innerHTML = `<div class="alert alert-info mb-0">Auto-retry attempt ${autoRetryAttempts}/10...</div>`;
        
        // Retry with 5 second delay between attempts
        await new Promise(r => setTimeout(r, 5000));
        
        const previousFailedCount = failedUploads.length;
        uploadQueue = failedUploads.map(f => f);
        const retrying = failedUploads.length;
        failedUploads = [];
        uploadedCount -= retrying;
        totalFiles = uploadedCount + retrying;
        
        progressBar.classList.add('progress-bar-animated');
        progressBar.classList.remove('bg-warning', 'bg-success');
        
        // Wait for uploads to complete, then check if we need to retry again
        await new Promise((resolve) => {
            const checkInterval = setInterval(() => {
                if (uploadQueue.length === 0 && activeUploads === 0) {
                    clearInterval(checkInterval);
                    setTimeout(() => {
                        if (failedUploads.length > 0 && failedUploads.length < previousFailedCount) {
                            // Progress made, continue auto-retry
                            window.autoRetryUntilSuccess();
                        } else if (failedUploads.length === 0) {
                            // All succeeded!
                            autoRetryAttempts = 0;
                        }
                        resolve();
                    }, 1000);
                }
            }, 500);
        });
        
        processUploadQueue();
    };

    async function calculateChecksum(file) {
        const buffer = await file.arrayBuffer();
        const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /* ---------- CSV IMPORT ---------- */
    csvForm.addEventListener('submit', async evt => {
        evt.preventDefault();
        const form = new FormData(csvForm);

        try {
            csvResult.innerHTML = '<div class="alert alert-info mb-0">Processing CSV import…</div>';

            const res = await fetch('/import/products', { method: 'POST', body: form });
            const data = await res.json();

            if (data.success) {
                renderCsvSummary(data.data);
            } else {
                renderCsvError(data.error || 'Import failed.', data.errors);
            }
        } catch (error) {
            console.error('CSV import failed', error);
            renderCsvError('Network or server error while importing CSV.');
        }
    });

    function renderCsvSummary(result) {
        const rows = [
            { label: 'Total Rows', value: result.total_rows },
            { label: 'Imported (New)', value: result.imported_count },
            { label: 'Updated (Existing)', value: result.updated_count },
            { label: 'Invalid Rows', value: result.invalid_count },
            { label: 'Duplicates (within CSV)', value: result.duplicate_count },
        ];

        if (result.images_linked !== undefined) {
            rows.push({ label: 'Images Linked', value: result.images_linked });
            rows.push({ label: 'Images Not Found', value: result.images_not_found });
        }

        let html = `
            <div class="alert alert-success mb-3">
                <h5 class="mb-2">✅ CSV Import Completed</h5>
                <p class="mb-0 small text-muted">Upserted by SKU with full summary below.</p>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm mb-0 align-middle">
                    <tbody>
                        ${rows.map(r => `
                            <tr>
                                <th scope="row" class="w-50">${r.label}</th>
                                <td><span class="badge bg-light text-dark">${r.value}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        if (result.errors && result.errors.length) {
            html += '<div class="alert alert-warning" style="max-height: 220px; overflow-y: auto;">';
            html += '<h6 class="mb-2">⚠️ Issues Found</h6>';
            result.errors.forEach((err, idx) => {
                html += `<div>${idx + 1}. ${err}</div>`;
            });
            html += '</div>';
        }

        csvResult.innerHTML = html;
    }

    function renderCsvError(message, errors = []) {
        let html = `
            <div class="alert alert-danger mb-2">
                <h5 class="mb-2">❌ Import Failed</h5>
                <p class="mb-0">${message}</p>
            </div>
        `;

        if (errors && errors.length) {
            html += '<div class="alert alert-warning" style="max-height: 200px; overflow-y: auto;">';
            errors.forEach((err, idx) => {
                html += `<div>${idx + 1}. ${err}</div>`;
            });
            html += '</div>';
        }

        csvResult.innerHTML = html;
    }
});
</script>
@endpush

