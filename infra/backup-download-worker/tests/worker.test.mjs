import assert from "node:assert/strict";
import { afterEach, test, mock } from "node:test";
import { DatabaseSync } from "node:sqlite";
import { readFileSync } from "node:fs";
import ts from "typescript";

// Compile in memory; all Google calls are mocked. No Worker, D1 or Drive is deployed.
const source = readFileSync(
    new URL("../src/index.ts", import.meta.url),
    "utf8",
);
const js = ts.transpileModule(source, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2023,
        module: ts.ModuleKind.ESNext,
    },
}).outputText;
const { handle } = await import(
    "data:text/javascript;base64," + Buffer.from(js).toString("base64")
);
afterEach(() => mock.restoreAll());

const uuid = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
function environment() {
    const sqlite = new DatabaseSync(":memory:");
    sqlite.exec(
        readFileSync(
            new URL("../migrations/0001_consumed_tickets.sql", import.meta.url),
            "utf8",
        ),
    );
    return {
        ENABLED: "true",
        DOWNLOAD_SIGNING_SECRET: "s".repeat(48),
        TICKET_KEY_ID: "test-key",
        TICKET_ISSUER: "test-issuer",
        TICKET_AUDIENCE: "eida-backup-download",
        DRIVE_FOLDER_ID: "private-folder",
        GOOGLE_CLIENT_ID: "test",
        GOOGLE_CLIENT_SECRET: "test",
        GOOGLE_REFRESH_TOKEN: "test",
        APP_ORIGIN: "https://app.example.invalid",
        TICKETS: {
            prepare(sql) {
                return {
                    bind(...args) {
                        return {
                            async run() {
                                const result = sqlite.prepare(sql).run(...args);
                                return {
                                    success: true,
                                    meta: { changes: Number(result.changes) },
                                };
                            },
                        };
                    },
                };
            },
        },
        sqlite,
    };
}
async function ticket(env, changes = {}) {
    const now = Math.floor(Date.now() / 1000);
    const claims = {
        jti: crypto.randomUUID(),
        backup_id: uuid,
        iss: env.TICKET_ISSUER,
        aud: env.TICKET_AUDIENCE,
        iat: now,
        exp: now + 60,
        file_id: "dump-id",
        parent_id: env.DRIVE_FOLDER_ID,
        filename: `eida-${uuid}.dump`,
        bytes: 4,
        sha256: "a".repeat(64),
        md5_checksum: null,
        storage_version: "1",
        ...changes,
    };
    const encode = (value) =>
        Buffer.from(JSON.stringify(value)).toString("base64url");
    const input =
        encode({ alg: "HS256", typ: "JWT", kid: env.TICKET_KEY_ID }) +
        "." +
        encode(claims);
    const key = await crypto.subtle.importKey(
        "raw",
        new TextEncoder().encode(env.DOWNLOAD_SIGNING_SECRET),
        { name: "HMAC", hash: "SHA-256" },
        false,
        ["sign"],
    );
    return (
        input +
        "." +
        Buffer.from(
            await crypto.subtle.sign(
                "HMAC",
                key,
                new TextEncoder().encode(input),
            ),
        ).toString("base64url")
    );
}
const request = (value) =>
    new Request("https://worker.example.invalid/download", {
        method: "POST",
        body: new URLSearchParams({ ticket: value }),
    });
function google(overrides = {}) {
    const calls = [];
    let metadataReads = 0;
    mock.method(globalThis, "fetch", async (url) => {
        calls.push(String(url));
        if (String(url).includes("oauth2.googleapis.com"))
            return Response.json({ access_token: "test" });
        if (String(url).endsWith("alt=media"))
            return new Response(
                new ReadableStream({
                    start(controller) {
                        controller.enqueue(new Uint8Array([1, 2, 3, 4]));
                        controller.close();
                    },
                }),
                { headers: { "Content-Length": "4" } },
            );
        return Response.json({
            id: "dump-id",
            name: `eida-${uuid}.dump`,
            mimeType: "application/octet-stream",
            size: "4",
            trashed: false,
            shared: false,
            ownedByMe: true,
            isAppAuthorized: true,
            parents: ["private-folder"],
            version: "1",
            appProperties: { backup_id: uuid, kind: "dump" },
            capabilities: { canDownload: true },
            ...(typeof overrides === "function"
                ? overrides(++metadataReads)
                : overrides),
        });
    });
    return calls;
}

test("streams private Drive body with attachment/no-store headers", async () => {
    const env = environment(),
        calls = google();
    const response = await handle(request(await ticket(env)), env);
    assert.equal(response.status, 200);
    assert.equal(response.headers.get("Cache-Control"), "private, no-store");
    assert.match(response.headers.get("Content-Disposition"), /^attachment;/);
    assert.equal(response.headers.get("Content-Length"), "4");
    const reader = response.body.getReader();
    assert.deepEqual((await reader.read()).value, new Uint8Array([1, 2, 3, 4]));
    assert.equal(calls.filter((url) => url.includes("alt=media")).length, 1);
    env.sqlite.close();
});

test("concurrent replay is arbitrated by SQL uniqueness before Google access", async () => {
    const env = environment(),
        calls = google(),
        value = await ticket(env);
    const results = await Promise.all([
        handle(request(value), env),
        handle(request(value), env),
    ]);
    assert.deepEqual(results.map((result) => result.status).sort(), [200, 409]);
    assert.equal(
        calls.filter((url) => url.includes("oauth2.googleapis.com")).length,
        1,
    );
    for (const response of results) await response.body?.cancel();
    env.sqlite.close();
});

test("metadata-only version change with matching SHA-256 downloads only once", async () => {
    const env = environment();
    const sha256 = Buffer.from(
        await crypto.subtle.digest("SHA-256", new Uint8Array([1, 2, 3, 4])),
    ).toString("hex");
    const calls = google({ version: "2", sha256Checksum: sha256 });
    const value = await ticket(env, { sha256 });
    const response = await handle(request(value), env);
    assert.equal(response.status, 200);
    assert.deepEqual(
        new Uint8Array(await response.arrayBuffer()),
        new Uint8Array([1, 2, 3, 4]),
    );
    const callCount = calls.length;
    const replay = await handle(request(value), env);
    assert.equal(replay.status, 409);
    assert.match(await replay.text(), /TICKET_CONSUMED/);
    assert.equal(calls.length, callCount);
    env.sqlite.close();
});

for (const corrupt of [false, true])
    test(`version change while opening media rechecks SHA-256 (corrupt=${corrupt})`, async () => {
        const env = environment();
        const sha256 = Buffer.from(
            await crypto.subtle.digest("SHA-256", new Uint8Array([1, 2, 3, 4])),
        ).toString("hex");
        const calls = google((read) => ({
            version: read === 1 ? "1" : "2",
            sha256Checksum: read > 1 && corrupt ? "b".repeat(64) : sha256,
        }));
        const response = await handle(request(await ticket(env, { sha256 })), env);
        assert.equal(response.status, corrupt ? 409 : 200);
        if (corrupt) assert.match(await response.text(), /INTEGRITY_MISMATCH/);
        else await response.body.cancel();
        assert.equal(calls.filter((url) => url.includes("alt=media")).length, 1);
        env.sqlite.close();
    });

test("failed version integrity check consumes the ticket and replay reports TICKET_CONSUMED", async () => {
    const env = environment();
    const calls = google({ version: "2", sha256Checksum: "b".repeat(64) });
    const value = await ticket(env);
    const first = await handle(request(value), env);
    assert.equal(first.status, 409);
    assert.match(await first.text(), /INTEGRITY_MISMATCH/);
    const callCount = calls.length;
    const replay = await handle(request(value), env);
    assert.equal(replay.status, 409);
    assert.match(await replay.text(), /TICKET_CONSUMED/);
    assert.equal(calls.length, callCount);
    assert.equal(
        env.sqlite.prepare("SELECT count(*) AS total FROM consumed_tickets").get().total,
        1,
    );
    env.sqlite.close();
});

test("invalid signature, expiry and audience do not consume tickets or contact Google", async () => {
    const env = environment(),
        calls = google();
    const wrongSignature =
        (await ticket(env)).split(".").slice(0, 2).join(".") + ".AAAA";
    assert.equal((await handle(request(wrongSignature), env)).status, 401);
    const now = Math.floor(Date.now() / 1000);
    assert.equal(
        (
            await handle(
                request(await ticket(env, { iat: now - 61, exp: now - 1 })),
                env,
            )
        ).status,
        410,
    );
    assert.equal(
        (await handle(request(await ticket(env, { aud: "other" })), env))
            .status,
        401,
    );
    assert.equal(calls.length, 0);
    assert.equal(
        env.sqlite
            .prepare("SELECT count(*) AS total FROM consumed_tickets")
            .get().total,
        0,
    );
    env.sqlite.close();
});

test("D1 outage fails closed and does not call OAuth", async () => {
    const env = environment(),
        calls = google();
    env.TICKETS.prepare = () => {
        throw new Error("database unavailable");
    };
    assert.equal((await handle(request(await ticket(env)), env)).status, 503);
    assert.equal(calls.length, 0);
    env.sqlite.close();
});

for (const changed of [
    { size: "5" },
    { shared: true },
    { trashed: true },
    { sha256Checksum: "b".repeat(64) },
    { version: "2" },
])
    test(`rejects mismatched/private/deleted metadata ${JSON.stringify(changed)}`, async () => {
        const env = environment(),
            calls = google(changed);
        const response = await handle(request(await ticket(env)), env);
        assert.ok([404, 409].includes(response.status));
        assert.equal(
            calls.filter((url) => url.includes("alt=media")).length,
            0,
        );
        env.sqlite.close();
    });

test("revoked OAuth and Drive quota return controlled errors; ticket stays consumed", async () => {
    const env = environment();
    mock.method(globalThis, "fetch", async () =>
        Response.json({ error: "invalid_grant" }, { status: 400 }),
    );
    const value = await ticket(env);
    assert.equal((await handle(request(value), env)).status, 503);
    assert.equal((await handle(request(value), env)).status, 409);
    mock.restoreAll();
    mock.method(globalThis, "fetch", async (url) =>
        String(url).includes("oauth2.googleapis.com")
            ? Response.json({ access_token: "test" })
            : Response.json(
                  { error: { errors: [{ reason: "downloadQuotaExceeded" }] } },
                  { status: 403 },
              ),
    );
    assert.equal((await handle(request(await ticket(env)), env)).status, 503);
    env.sqlite.close();
});

test("never buffers the dump or constructs an upstream URL from a request parameter", async () => {
    assert.doesNotMatch(source, /\.arrayBuffer\(|\.blob\(/);
    const env = environment(),
        calls = google();
    const response = await handle(
        new Request("https://worker.example.invalid/download?file_id=other", {
            method: "POST",
            body: new URLSearchParams({ ticket: await ticket(env) }),
        }),
        env,
    );
    assert.equal(response.status, 400);
    assert.equal(calls.length, 0);
    env.sqlite.close();
});
