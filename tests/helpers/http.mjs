import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';

/** Decode URL attributes emitted by WordPress esc_url(), including numeric entities. */
export function decodeHtmlAttribute(value) {
    return value.replace(/&amp;|&#(?:x([0-9a-f]+)|([0-9]+));/gi, (entity, hex, decimal) => {
        if (entity.toLowerCase() === '&amp;') return '&';
        const point = Number.parseInt(hex ?? decimal, hex ? 16 : 10);
        return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
    });
}

/** Buffer each response before the next request; redirects remain explicit. */
export async function fetchResponse(url, options = {}) {
    const input = new Request(url, options);
    const body = input.body ? Buffer.from(await input.arrayBuffer()) : null;
    const headers = Object.fromEntries(input.headers);
    headers.connection = 'close';
    if (body) headers['content-length'] = String(body.length);
    const transport = new URL(input.url).protocol === 'https:' ? httpsRequest : httpRequest;

    return new Promise((resolve, reject) => {
        const outgoing = transport(input.url, { method: input.method, headers }, incoming => {
            const chunks = [];
            incoming.on('data', chunk => chunks.push(chunk));
            incoming.on('error', reject);
            incoming.on('end', () => {
                const responseHeaders = new Headers();
                for (let i = 0; i < incoming.rawHeaders.length; i += 2) {
                    responseHeaders.append(incoming.rawHeaders[i], incoming.rawHeaders[i + 1]);
                }
                const noBody = input.method === 'HEAD' || [204, 205, 304].includes(incoming.statusCode);
                resolve(new Response(noBody ? null : Buffer.concat(chunks), {
                    status: incoming.statusCode,
                    headers: responseHeaders
                }));
            });
        });
        outgoing.on('error', reject);
        outgoing.setTimeout(30_000, () => outgoing.destroy(new Error('HTTP test request timed out')));
        outgoing.end(body);
    });
}
