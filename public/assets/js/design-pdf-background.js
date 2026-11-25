export const DESIGN_PDF_MAX_BYTES = 10_485_760;
export const DESIGN_BACKGROUND_WIDTH = 1011;
export const DESIGN_BACKGROUND_HEIGHT = 638;

export async function convertPdfFirstPage(file, pdfjsLib, createCanvas = () => document.createElement('canvas')) {
    if (!file || !Number.isSafeInteger(file.size) || file.size < 1 || file.size > DESIGN_PDF_MAX_BYTES) {
        throw new Error('pdf_size_invalid');
    }
    if (!pdfjsLib || typeof pdfjsLib.getDocument !== 'function') throw new Error('pdf_renderer_unavailable');

    const data = new Uint8Array(await file.arrayBuffer());
    const loadingTask = pdfjsLib.getDocument({data, isEvalSupported: false, enableXfa: false});
    let rejectPassword;
    const passwordRequired = new Promise((resolve, reject) => { rejectPassword = reject; });
    loadingTask.onPassword = () => {
        rejectPassword(new Error('pdf_password_protected'));
    };

    let pdf;
    try {
        pdf = await Promise.race([loadingTask.promise, passwordRequired]);
        if (!pdf || !Number.isInteger(pdf.numPages) || pdf.numPages < 1) throw new Error('pdf_document_invalid');
        const page = await pdf.getPage(1);
        const sourceViewport = page.getViewport({scale: 1});
        if (!Number.isFinite(sourceViewport.width) || !Number.isFinite(sourceViewport.height)
            || sourceViewport.width <= 0 || sourceViewport.height <= 0) {
            throw new Error('pdf_page_invalid');
        }
        const scale = Math.min(
            DESIGN_BACKGROUND_WIDTH / sourceViewport.width,
            DESIGN_BACKGROUND_HEIGHT / sourceViewport.height
        );
        const viewport = page.getViewport({scale});
        const canvas = createCanvas();
        canvas.width = DESIGN_BACKGROUND_WIDTH;
        canvas.height = DESIGN_BACKGROUND_HEIGHT;
        const context = canvas.getContext('2d', {alpha: false});
        if (!context) throw new Error('pdf_canvas_unavailable');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({
            canvasContext: context,
            viewport,
            transform: [1, 0, 0, 1, (canvas.width - viewport.width) / 2, (canvas.height - viewport.height) / 2],
            background: '#ffffff',
        }).promise;
        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => blob && blob.type === 'image/png' ? resolve(blob) : reject(new Error('pdf_png_encode_failed')), 'image/png');
        });
    } finally {
        if (pdf && typeof pdf.destroy === 'function') await pdf.destroy();
        else if (typeof loadingTask.destroy === 'function') await loadingTask.destroy();
    }
}
