/**
 * Combine the camera canvas and the 3D canvas into one JPEG.
 * Must be called synchronously from the tracker callback, while the camera
 * frame is still in the WebGL drawing buffer.
 */
export function composeSnapshot(cameraCanvas, sceneCanvas, { mirror = false, caption = '' } = {}) {
    const canvas = document.createElement('canvas');
    canvas.width = cameraCanvas.width;
    canvas.height = cameraCanvas.height;
    const context = canvas.getContext('2d');

    if (mirror) {
        context.translate(canvas.width, 0);
        context.scale(-1, 1);
    }
    context.drawImage(cameraCanvas, 0, 0, canvas.width, canvas.height);
    context.drawImage(sceneCanvas, 0, 0, canvas.width, canvas.height);
    context.setTransform(1, 0, 0, 1, 0, 0);

    if (caption) {
        const fontSize = Math.max(14, Math.round(canvas.width / 36));
        context.font = `600 ${fontSize}px system-ui, sans-serif`;
        context.textAlign = 'right';
        context.textBaseline = 'bottom';
        context.shadowColor = 'rgba(0, 0, 0, 0.6)';
        context.shadowBlur = fontSize / 3;
        context.fillStyle = 'rgba(255, 255, 255, 0.92)';
        context.fillText(caption, canvas.width - fontSize, canvas.height - fontSize);
    }

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
}
