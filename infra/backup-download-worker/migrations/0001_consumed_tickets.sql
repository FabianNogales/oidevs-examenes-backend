-- D1 stores only redemptions. A primary-key insert arbitrates concurrent replay.
CREATE TABLE consumed_tickets (
    jti TEXT PRIMARY KEY NOT NULL,
    expires_at INTEGER NOT NULL,
    consumed_at INTEGER NOT NULL
);
CREATE INDEX consumed_tickets_expiry ON consumed_tickets (expires_at);
