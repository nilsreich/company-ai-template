const root = document.getElementById('app-feedback');

if (root) {
    const byId = (name) => document.getElementById(`feedback-${name}`);
    const dialog = byId('dialog');
    const form = byId('form');
    const launcher = byId('open');
    const capture = byId('capture');
    const canvas = byId('canvas');
    const preview = byId('preview');
    const status = byId('status');
    const submit = byId('submit');
    const fields = byId('fields');
    const context = canvas.getContext('2d');
    const configured = !submit.disabled;
    let hasScreenshot = false;
    let capturing = false;
    let sending = false;
    let finished = false;
    let startPoint = null;
    let drawingBase = null;
    let stream = null;
    let pendingData = null;

    const stopCapture = () => {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    };
    const removeScreenshot = () => {
        hasScreenshot = false;
        canvas.width = canvas.height = 0;
        preview.hidden = true;
        drawingBase = null;
        startPoint = null;
    };
    const reset = () => {
        if (sending || pendingData) return;
        stopCapture();
        removeScreenshot();
        form.reset();
        status.textContent = '';
        if (finished) {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;
            const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            root.dataset.requestId = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
            finished = false;
            fields.disabled = false;
            submit.disabled = !configured;
            byId('issue').hidden = true;
            byId('issue').removeAttribute('href');
        }
    };
    const close = () => {
        if (sending || capturing) return;
        reset();
        dialog.close();
        launcher.focus();
    };
    launcher.addEventListener('click', () => dialog.showModal());
    byId('close').addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    window.addEventListener('pagehide', stopCapture);
    byId('remove').addEventListener('click', removeScreenshot);

    if (!window.isSecureContext || !navigator.mediaDevices?.getDisplayMedia) {
        capture.disabled = true;
        byId('capture-hint').textContent = !window.isSecureContext
            ? 'Die native Screenshot-Aufnahme benötigt HTTPS oder localhost. Unter dieser HTTP-LAN-Adresse kannst du Feedback ohne Screenshot senden.'
            : 'Dieser Browser unterstützt die native Bildschirmaufnahme nicht. Feedback ohne Screenshot ist weiterhin möglich.';
    }

    capture.addEventListener('click', async () => {
        if (capturing || sending) return;
        capturing = true;
        status.textContent = '';
        dialog.classList.add('feedback-capturing');
        dialog.style.visibility = 'hidden';
        launcher.style.visibility = 'hidden';
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        let timeout;
        try {
            // Keep this call inside the click event: the browser requires user activation.
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: { displaySurface: 'browser' }, audio: false,
                preferCurrentTab: true, selfBrowserSurface: 'include',
                systemAudio: 'exclude', monitorTypeSurfaces: 'exclude',
            });
            video.srcObject = stream;
            await Promise.race([
                (async () => {
                    const firstFrame = video.requestVideoFrameCallback
                        ? new Promise((resolve) => video.requestVideoFrameCallback(resolve))
                        : Promise.resolve();
                    await video.play();
                    await firstFrame;
                })(),
                new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('capture-timeout')), 10000); }),
            ]);
            if (!video.videoWidth || !video.videoHeight) throw new Error('empty-frame');
            const scale = Math.min(1, 2400 / Math.max(video.videoWidth, video.videoHeight));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            hasScreenshot = true;
            preview.hidden = false;
        } catch (error) {
            status.textContent = error.name === 'NotAllowedError'
                ? 'Aufnahme abgebrochen. Du kannst Feedback auch ohne Screenshot senden.'
                : 'Die Aufnahme konnte nicht erstellt werden. Bitte erneut versuchen oder ohne Screenshot fortfahren.';
        } finally {
            clearTimeout(timeout);
            stopCapture();
            video.srcObject = null;
            capturing = false;
            dialog.classList.remove('feedback-capturing');
            dialog.style.visibility = '';
            launcher.style.visibility = '';
            capture.focus();
        }
    });

    const point = (event) => {
        const rect = canvas.getBoundingClientRect();
        return { x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height };
    };
    canvas.addEventListener('pointerdown', (event) => {
        if (!hasScreenshot || sending || finished) return;
        startPoint = point(event);
        drawingBase = context.getImageData(0, 0, canvas.width, canvas.height);
        canvas.setPointerCapture(event.pointerId);
    });
    const redact = (event) => {
        if (!startPoint || !drawingBase) return;
        const end = point(event);
        context.putImageData(drawingBase, 0, 0);
        context.fillStyle = '#000000';
        context.fillRect(Math.min(startPoint.x, end.x), Math.min(startPoint.y, end.y), Math.abs(end.x - startPoint.x), Math.abs(end.y - startPoint.y));
    };
    canvas.addEventListener('pointermove', redact);
    canvas.addEventListener('pointerup', (event) => {
        redact(event);
        startPoint = drawingBase = null;
    });
    canvas.addEventListener('pointercancel', () => { startPoint = drawingBase = null; });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (sending || capturing || finished || !form.reportValidity()) return;
        sending = true;
        const data = pendingData || new FormData(form);
        data.set('request_id', root.dataset.requestId);
        submit.disabled = fields.disabled = true;
        byId('close').disabled = true;
        status.textContent = 'Feedback wird übertragen …';
        let accepted = false;
        try {
            if (hasScreenshot && !pendingData) {
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                if (!blob || blob.size > 5 * 1024 * 1024) {
                    status.textContent = 'Der Screenshot ist zu groß (maximal 5 MB). Entferne ihn oder wähle einen kleineren Bereich.';
                    return;
                }
                data.set('screenshot', blob, 'screenshot.png');
            }
            pendingData = data;
            const response = await fetch(root.dataset.endpoint, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: AbortSignal.timeout(45000),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                pendingData = null;
                status.textContent = response.status === 422
                    ? Object.values(result.errors || {}).flat().join(' ') || 'Bitte prüfe die Eingaben.'
                    : response.status === 419 || response.status === 401
                        ? 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.'
                        : response.status === 429 ? 'Zu viele Anfragen. Bitte warte eine Minute.'
                            : result.message || 'Der Versand ist momentan nicht möglich. Bitte erneut versuchen.';
                return;
            }
            accepted = finished = true;
            pendingData = null;
            if (result.status === 'sent') {
                status.textContent = 'Danke! Dein Feedback wurde als GitHub-Issue gespeichert.';
                const link = byId('issue');
                if (typeof result.issue_url === 'string' && /^https:\/\/github\.com\/[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+\/issues\/\d+$/.test(result.issue_url)) {
                    link.href = result.issue_url;
                    link.hidden = false;
                }
            } else {
                status.textContent = result.status === 'failed'
                    ? `GitHub hat den Versand abgelehnt. Dein Feedback ist intern gespeichert. Bitte melde einem Administrator die Referenz ${result.id}.`
                    : `Der Versand konnte nicht eindeutig bestätigt werden. Bitte nicht erneut absenden. Ein Administrator kann mit Referenz ${result.id} prüfen, ob das Issue angelegt wurde.`;
            }
            removeScreenshot();
        } catch {
            // Retain the same request ID; another attempt cannot create a second issue.
            status.textContent = 'Keine Bestätigung erhalten. Erneutes Absenden mit derselben Referenz ist möglich; doppelte Issues werden verhindert.';
        } finally {
            sending = false;
            submit.disabled = accepted;
            fields.disabled = accepted || !!pendingData;
            byId('close').disabled = false;
        }
    });
}
