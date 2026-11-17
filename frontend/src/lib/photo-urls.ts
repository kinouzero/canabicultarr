/** Transform only app-owned image URLs; uploaded blobs and external URLs stay intact. */
export function photoThumbnailUrl(url: string): string {
 return url.replace(/^(\/api\/v1\/documents\/[^/?#]+)\/preview(?=[?#]|$)/, '$1/thumbnail');
}

export function photoPreviewUrl(url: string): string {
 return url.replace(/^(\/api\/v1\/documents\/[^/?#]+)\/preview(?=[?#]|$)/, '$1/image-preview');
}
