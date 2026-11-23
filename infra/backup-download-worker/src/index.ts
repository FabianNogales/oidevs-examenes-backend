/** No application cookies, Google browser login, R2 or buffered dump bodies. */
export interface Env {
    ENABLED: string;
    DOWNLOAD_SIGNING_SECRET: string;
    TICKET_KEY_ID: string;
    TICKET_ISSUER: string;
    TICKET_AUDIENCE: string;
    DRIVE_FOLDER_ID: string;
    GOOGLE_CLIENT_ID: string;
    GOOGLE_CLIENT_SECRET: string;
    GOOGLE_REFRESH_TOKEN: string;
    APP_ORIGIN: string;
    TICKETS: {
        prepare(sql: string): {
            bind(...values: unknown[]): {
                run(): Promise<{
                    success: boolean;
                    meta: { changes?: number };
                }>;
            };
        };
    };
}

interface Claims {
    jti: string;
    backup_id: string;
    iss: string;
    aud: string;
    iat: number;
    exp: number;
    file_id: string;
    parent_id: string;
    filename: string;
    bytes: number;
    sha256: string;
    md5_checksum: string | null;
    storage_version: string;
}

class DownloadFailure extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
    ) {
        super(code);
    }
}

const messages: Record<string, string> = {
    INVALID_TICKET:
        "La autorización de descarga no es válida. Solicita otra desde EIDA.",
    TICKET_EXPIRED:
        "La autorización de descarga ha caducado. Solicita otra desde EIDA.",
    TICKET_CONSUMED:
        "Esta autorización ya se utilizó. Solicita otra desde EIDA.",
    SERVICE_UNAVAILABLE:
        "La descarga no está disponible. Intenta nuevamente desde EIDA.",
    OAUTH_REVOKED:
        "El acceso al almacenamiento requiere revisión del administrador del sistema.",
    FILE_MISSING: "El archivo ya no está disponible en el almacenamiento.",
    INTEGRITY_MISMATCH:
        "El archivo no coincide con el respaldo autorizado. La descarga se ha detenido.",
    DRIVE_PERMISSION_DENIED:
        "El almacenamiento no permite descargar este archivo.",
    DRIVE_QUOTA_EXCEEDED:
        "Se alcanzó una cuota de Google Drive. Intenta más tarde.",
    DRIVE_RATE_LIMITED:
        "Google Drive está limitando las solicitudes. Intenta más tarde.",
    INVALID_REQUEST:
        "Solicita la descarga mediante el botón Descargar de EIDA.",
};
const noStore = {
    "Cache-Control": "private, no-store",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
};
const idPattern = /^[A-Za-z0-9_-]{1,200}$/;
const uuidPattern =
    /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;

async function boundedText(
    body: ReadableStream<Uint8Array> | null,
    max: number,
): Promise<string> {
    if (!body) throw new DownloadFailure("INVALID_REQUEST", 400);
    const reader = body.getReader();
    const decoder = new TextDecoder("utf-8", { fatal: true });
    let length = 0;
    let value = "";
    try {
        while (true) {
            const part = await reader.read();
            if (part.done) return value + decoder.decode();
            length += part.value.byteLength;
            if (length > max) throw new DownloadFailure("INVALID_REQUEST", 413);
            value += decoder.decode(part.value, { stream: true });
        }
    } finally {
        await reader.cancel().catch(() => undefined);
        reader.releaseLock();
    }
}

function decodePart(value: string): Uint8Array<ArrayBuffer> {
    if (!/^[A-Za-z0-9_-]+$/.test(value))
        throw new DownloadFailure("INVALID_TICKET", 401);
    try {
        const binary = atob(value.replace(/-/g, "+").replace(/_/g, "/"));
        return Uint8Array.from(binary, (character) => character.charCodeAt(0));
    } catch {
        throw new DownloadFailure("INVALID_TICKET", 401);
    }
}

export async function verifyTicket(
    ticket: string,
    env: Env,
    now = Math.floor(Date.now() / 1000),
): Promise<Claims> {
    if (ticket.length > 4096) throw new DownloadFailure("INVALID_TICKET", 401);
    const parts = ticket.split(".");
    if (parts.length !== 3) throw new DownloadFailure("INVALID_TICKET", 401);
    let header: { alg?: string; typ?: string; kid?: string };
    try {
        header = JSON.parse(new TextDecoder().decode(decodePart(parts[0])));
    } catch {
        throw new DownloadFailure("INVALID_TICKET", 401);
    }
    if (
        header?.alg !== "HS256" ||
        header.typ !== "JWT" ||
        header.kid !== env.TICKET_KEY_ID
    ) {
        throw new DownloadFailure("INVALID_TICKET", 401);
    }
    const key = await crypto.subtle.importKey(
        "raw",
        new TextEncoder().encode(env.DOWNLOAD_SIGNING_SECRET),
        { name: "HMAC", hash: "SHA-256" },
        false,
        ["verify"],
    );
    if (
        !(await crypto.subtle.verify(
            "HMAC",
            key,
            decodePart(parts[2]),
            new TextEncoder().encode(parts[0] + "." + parts[1]),
        ))
    ) {
        throw new DownloadFailure("INVALID_TICKET", 401);
    }
    let claims: Claims;
    try {
        claims = JSON.parse(new TextDecoder().decode(decodePart(parts[1])));
    } catch {
        throw new DownloadFailure("INVALID_TICKET", 401);
    }
    if (
        !claims ||
        claims.iss !== env.TICKET_ISSUER ||
        claims.aud !== env.TICKET_AUDIENCE ||
        !Number.isSafeInteger(claims.iat) ||
        !Number.isSafeInteger(claims.exp) ||
        claims.exp - claims.iat !== 60 ||
        claims.iat > now + 5 ||
        !uuidPattern.test(claims.jti) ||
        !uuidPattern.test(claims.backup_id) ||
        typeof claims.file_id !== "string" ||
        !idPattern.test(claims.file_id) ||
        claims.parent_id !== env.DRIVE_FOLDER_ID ||
        claims.filename !== `eida-${claims.backup_id}.dump` ||
        !Number.isSafeInteger(claims.bytes) ||
        claims.bytes < 1 ||
        !/^[a-f0-9]{64}$/.test(claims.sha256) ||
        !(
            claims.md5_checksum === null ||
            /^[a-f0-9]{32}$/.test(claims.md5_checksum)
        ) ||
        typeof claims.storage_version !== "string" ||
        !/^[0-9]{1,100}$/.test(claims.storage_version)
    )
        throw new DownloadFailure("INVALID_TICKET", 401);
    if (claims.exp <= now) throw new DownloadFailure("TICKET_EXPIRED", 410);
    return claims;
}

async function consume(claims: Claims, env: Env): Promise<void> {
    try {
        // All D1 writes go to its primary. Uniqueness is the lock, not a prior SELECT.
        const result = await env.TICKETS.prepare(
            "INSERT INTO consumed_tickets (jti, expires_at, consumed_at) VALUES (?, ?, ?) ON CONFLICT(jti) DO NOTHING",
        )
            .bind(claims.jti, claims.exp, Math.floor(Date.now() / 1000))
            .run();
        if (!result.success || typeof result.meta.changes !== "number")
            throw new DownloadFailure("SERVICE_UNAVAILABLE", 503);
        if (result.meta.changes !== 1)
            throw new DownloadFailure("TICKET_CONSUMED", 409);
    } catch (error) {
        if (error instanceof DownloadFailure) throw error;
        throw new DownloadFailure("SERVICE_UNAVAILABLE", 503);
    }
}

async function jsonResponse(
    response: Response,
): Promise<Record<string, unknown>> {
    const text = await boundedText(response.body, 65536);
    try {
        return JSON.parse(text);
    } catch {
        throw new DownloadFailure("SERVICE_UNAVAILABLE", 503);
    }
}

async function accessToken(env: Env): Promise<string> {
    const response = await fetch("https://oauth2.googleapis.com/token", {
        method: "POST",
        redirect: "error",
        signal: AbortSignal.timeout(15000),
        body: new URLSearchParams({
            client_id: env.GOOGLE_CLIENT_ID,
            client_secret: env.GOOGLE_CLIENT_SECRET,
            refresh_token: env.GOOGLE_REFRESH_TOKEN,
            grant_type: "refresh_token",
        }),
    });
    const result = await jsonResponse(response);
    if (
        !response.ok ||
        typeof result.access_token !== "string" ||
        !result.access_token
    ) {
        throw new DownloadFailure(
            result.error === "invalid_grant"
                ? "OAUTH_REVOKED"
                : "SERVICE_UNAVAILABLE",
            503,
        );
    }
    return result.access_token;
}

async function googleError(response: Response): Promise<never> {
    if (response.status === 401) {
        await response.body?.cancel();
        throw new DownloadFailure("OAUTH_REVOKED", 503);
    }
    if (response.status === 404) {
        await response.body?.cancel();
        throw new DownloadFailure("FILE_MISSING", 404);
    }
    if (response.status === 429) {
        await response.body?.cancel();
        throw new DownloadFailure("DRIVE_RATE_LIMITED", 429);
    }
    let reason = "";
    try {
        const data = (await jsonResponse(response)) as {
            error?: { errors?: { reason?: string }[] };
        };
        reason = data.error?.errors?.[0]?.reason ?? "";
    } catch {
        /* Only bounded error parsing; never echo a provider body. */
    }
    if (
        [
            "storageQuotaExceeded",
            "dailyLimitExceeded",
            "downloadQuotaExceeded",
        ].includes(reason)
    )
        throw new DownloadFailure("DRIVE_QUOTA_EXCEEDED", 503);
    if (["rateLimitExceeded", "userRateLimitExceeded"].includes(reason))
        throw new DownloadFailure("DRIVE_RATE_LIMITED", 429);
    throw new DownloadFailure(
        response.status === 403
            ? "DRIVE_PERMISSION_DENIED"
            : "SERVICE_UNAVAILABLE",
        503,
    );
}

async function metadata(claims: Claims, token: string): Promise<void> {
    const fields =
        "id,name,mimeType,size,trashed,parents,shared,ownedByMe,isAppAuthorized,version,sha256Checksum,md5Checksum,appProperties,capabilities(canDownload)";
    const response = await fetch(
        `https://www.googleapis.com/drive/v3/files/${encodeURIComponent(claims.file_id)}?fields=${encodeURIComponent(fields)}`,
        {
            headers: { Authorization: `Bearer ${token}` },
            redirect: "error",
            signal: AbortSignal.timeout(15000),
        },
    );
    if (!response.ok) await googleError(response);
    const file = await jsonResponse(response);
    const props = file.appProperties as Record<string, string> | undefined;
    if (file.trashed === true) throw new DownloadFailure("FILE_MISSING", 404);
    if (
        file.id !== claims.file_id ||
        file.name !== claims.filename ||
        file.mimeType !== "application/octet-stream" ||
        file.trashed !== false ||
        file.shared !== false ||
        file.ownedByMe !== true ||
        file.isAppAuthorized !== true ||
        (file.capabilities as { canDownload?: boolean })?.canDownload !==
            true ||
        JSON.stringify(file.parents) !== JSON.stringify([claims.parent_id]) ||
        file.size !== String(claims.bytes) ||
        typeof file.version !== "string" ||
        !/^[0-9]{1,100}$/.test(file.version) ||
        // Drive versions include metadata changes; a different version needs
        // Drive's current SHA-256 to match the content authorized by Laravel.
        (file.version !== claims.storage_version &&
            file.sha256Checksum !== claims.sha256) ||
        props?.backup_id !== claims.backup_id ||
        props?.kind !== "dump" ||
        (file.sha256Checksum !== undefined &&
            file.sha256Checksum !== claims.sha256) ||
        (file.md5Checksum !== undefined &&
            file.md5Checksum !== claims.md5_checksum)
    )
        throw new DownloadFailure("INTEGRITY_MISMATCH", 409);
}

function failureResponse(error: DownloadFailure, env: Env): Response {
    const code = error.code in messages ? error.code : "SERVICE_UNAVAILABLE";
    const nonce = crypto.randomUUID();
    let script = "";
    try {
        const origin = new URL(env.APP_ORIGIN).origin;
        if (origin === env.APP_ORIGIN && origin.startsWith("https://")) {
            // Origin is only a UI notification destination, never download authorization.
            script = `<script nonce="${nonce}">window.opener?.postMessage({type:'eida-backup-download-error',code:${JSON.stringify(code)}},${JSON.stringify(origin)});</script>`;
        }
    } catch {
        /* The error page remains usable without an opener. */
    }
    return new Response(
        `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Descarga de respaldo</title><body><h1>No se pudo iniciar la descarga</h1><p role="alert">${messages[code]}</p><p>Vuelve a EIDA y solicita una nueva descarga. Puedes cerrar esta ventana.</p>${script}</body></html>`,
        {
            status: error.status,
            headers: {
                ...noStore,
                "Content-Type": "text/html; charset=utf-8",
                "Content-Security-Policy": `default-src 'none'; script-src 'nonce-${nonce}'; frame-ancestors 'none'; base-uri 'none'`,
            },
        },
    );
}

export async function handle(request: Request, env: Env): Promise<Response> {
    try {
        if (
            env.ENABLED !== "true" ||
            !env.TICKETS ||
            env.DOWNLOAD_SIGNING_SECRET?.length < 32 ||
            !env.DOWNLOAD_SIGNING_SECRET ||
            !env.TICKET_KEY_ID ||
            !env.TICKET_ISSUER ||
            env.TICKET_AUDIENCE !== "eida-backup-download" ||
            !idPattern.test(env.DRIVE_FOLDER_ID) ||
            !env.GOOGLE_CLIENT_ID ||
            !env.GOOGLE_CLIENT_SECRET ||
            !env.GOOGLE_REFRESH_TOKEN
        )
            throw new DownloadFailure("SERVICE_UNAVAILABLE", 503);
        const url = new URL(request.url);
        if (
            request.method !== "POST" ||
            url.protocol !== "https:" ||
            url.pathname !== "/download" ||
            url.search ||
            request.headers.has("Range") ||
            !request.headers
                .get("Content-Type")
                ?.startsWith("application/x-www-form-urlencoded")
        )
            throw new DownloadFailure("INVALID_REQUEST", 400);
        const form = new URLSearchParams(await boundedText(request.body, 8192));
        if (form.size !== 1 || !form.has("ticket"))
            throw new DownloadFailure("INVALID_REQUEST", 400);
        const claims = await verifyTicket(form.get("ticket")!, env);
        await consume(claims, env); // Fail closed before OAuth or any file access.
        if (claims.exp <= Math.floor(Date.now() / 1000))
            throw new DownloadFailure("TICKET_EXPIRED", 410);
        const token = await accessToken(env);
        await metadata(claims, token);
        // No signal timeout on the streaming response body: the ticket limits start,
        // not the duration of an authorized transfer. Client disconnection cancels fetch.
        const upstream = await fetch(
            `https://www.googleapis.com/drive/v3/files/${encodeURIComponent(claims.file_id)}?alt=media`,
            {
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Accept-Encoding": "identity",
                },
                redirect: "error",
                signal: request.signal,
            },
        );
        if (!upstream.ok) await googleError(upstream);
        try {
            // Recheck identity, size and content hash after opening media.
            // No dump buffering; only a second small metadata response is read here.
            await metadata(claims, token);
            if (claims.exp <= Math.floor(Date.now() / 1000))
                throw new DownloadFailure("TICKET_EXPIRED", 410);
        } catch (error) {
            await upstream.body?.cancel();
            throw error;
        }
        const length = upstream.headers.get("Content-Length");
        if (
            upstream.status !== 200 ||
            !upstream.body ||
            (length !== null && length !== String(claims.bytes))
        ) {
            await upstream.body?.cancel();
            throw new DownloadFailure("INTEGRITY_MISMATCH", 409);
        }
        const headers = new Headers({
            ...noStore,
            "Content-Type": "application/octet-stream",
            "Content-Disposition": `attachment; filename="${claims.filename}"`,
            "Accept-Ranges": "none",
        });
        if (length !== null) headers.set("Content-Length", length);
        return new Response(upstream.body, { status: 200, headers });
    } catch (error) {
        // Never log requests (ticket in body), OAuth responses, exceptions or tokens.
        return failureResponse(
            error instanceof DownloadFailure
                ? error
                : new DownloadFailure("SERVICE_UNAVAILABLE", 503),
            env,
        );
    }
}

export default { fetch: handle };
