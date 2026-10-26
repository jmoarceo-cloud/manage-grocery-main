var decoderModulePromise = null;

function loadDecoderModule() {
    if (!decoderModulePromise) {
        decoderModulePromise = import('./zxing-wasm/dist/es/reader/index.js')
            .then(function (module) {
                module.setZXingModuleOverrides({
                    locateFile: function () {
                        return new URL('./zxing-wasm/dist/reader/zxing_reader.wasm', import.meta.url).href;
                    }
                });
                return module;
            }).catch(function (reason) {
                decoderModulePromise = null;
                throw reason;
            });
    }
    return decoderModulePromise;
}

export function readBarcodeImageWithZXing(file) {
    return loadDecoderModule().then(function (module) {
        return module.readBarcodes(file, {
            formats: [],
            maxNumberOfSymbols: 1,
            tryHarder: true
        });
    });
}
