export async function comparePixels(page, native, original) {
    return page.evaluate(async ({ native, original }) => {
        async function pixels(value) {
            const image = new Image();
            image.src = `data:image/png;base64,${value}`;
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = image.width;
            canvas.height = image.height;
            const context = canvas.getContext('2d');
            context.drawImage(image, 0, 0);
            return { data: context.getImageData(0, 0, image.width, image.height).data, width: image.width, height: image.height };
        }
        const first = await pixels(native), second = await pixels(original);
        if (first.width !== second.width || first.height !== second.height) throw new Error('Reference dimensions differ.');
        let changed = 0;
        for (let i = 0; i < first.data.length; i += 4) {
            if (Math.max(...[0, 1, 2].map(channel => Math.abs(first.data[i + channel] - second.data[i + channel]))) > 8) changed++;
        }
        return { changed_pixels: changed, total_pixels: first.width * first.height, ratio: changed / (first.width * first.height) };
    }, { native: native.toString('base64'), original: original.toString('base64') });
}
