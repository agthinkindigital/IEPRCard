import assert from 'node:assert/strict';
import {convertPdfFirstPage, DESIGN_PDF_MAX_BYTES} from '../../public/assets/js/design-pdf-background.js';

const file = new Blob(['synthetic pdf bytes'], {type: 'application/pdf'});
const calls = {pages: [], render: null, destroy: 0, fill: null};
const fakePdf = {
    numPages: 3,
    async getPage(pageNumber) {
        calls.pages.push(pageNumber);
        return {
            getViewport: ({scale}) => ({width: 2022 * scale, height: 638 * scale}),
            render(options) {
                calls.render = options;
                return {promise: Promise.resolve()};
            },
        };
    },
    async destroy() { calls.destroy += 1; },
};
const fakePdfjs = {
    getDocument(options) {
        assert.ok(options.data instanceof Uint8Array);
        assert.equal(options.isEvalSupported, false);
        return {promise: Promise.resolve(fakePdf), destroy: async () => {}};
    },
};
const blob = await convertPdfFirstPage(file, fakePdfjs, () => ({
    width: 0,
    height: 0,
    getContext: () => ({fillStyle: '', fillRect(...args) { calls.fill = args; }}),
    toBlob(callback, type) { callback(new Blob(['png-bytes'], {type})); },
}));
assert.equal(blob.type, 'image/png');
assert.deepEqual(calls.pages, [1], 'conversion must request only the first page');
assert.deepEqual(calls.fill, [0, 0, 1011, 638]);
assert.deepEqual(calls.render.transform, [1, 0, 0, 1, 0, 159.5]);
assert.equal(calls.render.viewport.width, 1011);
assert.equal(calls.destroy, 1);

await assert.rejects(
    convertPdfFirstPage({size: DESIGN_PDF_MAX_BYTES + 1}, fakePdfjs, () => null),
    /pdf_size_invalid/
);

let passwordDestroyCount = 0;
const passwordTask = {
    promise: new Promise(() => {}),
    async destroy() { passwordDestroyCount += 1; },
};
const passwordPdfjs = {
    getDocument() {
        queueMicrotask(() => passwordTask.onPassword?.(() => {}, 1));
        return passwordTask;
    },
};
await assert.rejects(convertPdfFirstPage(file, passwordPdfjs), /pdf_password_protected/);
assert.equal(passwordDestroyCount, 1, 'password-protected PDF loading must stop');

process.stdout.write('PASS: PDF background conversion renders one page locally to a fixed PNG and rejects oversized/password files.\n');
