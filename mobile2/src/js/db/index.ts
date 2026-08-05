import { Capacitor } from '@capacitor/core';
import {
  CapacitorSQLite,
  SQLiteConnection,
  SQLiteDBConnection,
} from '@capacitor-community/sqlite';
import { DB_NAME, DB_VERSION, SCHEMA_STATEMENTS } from './schema';

let connection: SQLiteConnection | null = null;
let db: SQLiteDBConnection | null = null;
let initPromise: Promise<void> | null = null;

function log(msg: string): void {
  console.log('[db]', msg);
}

/**
 * Open (or resume) the app database and apply schema migrations.
 * Safe to call multiple times; returns the same database handle.
 */
export async function initDb(): Promise<void> {
  if (initPromise) {
    return initPromise;
  }
  initPromise = (async () => {
    if (Capacitor.getPlatform() === 'web') {
      log('SQLite is not available on web — using in-memory stubs.');
      return;
    }
    try {
      // The community plugin requests its (auto-granted) Android permission
      // before creating connections. Wrap in try/catch: harmless on modern
      // Android where the internal database dir needs no permission.
      const sqlitePlugin = CapacitorSQLite as unknown as {
        checkPermissions: () => Promise<{ android?: string }>;
        requestPermissions: () => Promise<unknown>;
      };
      try {
        const perms = await sqlitePlugin.checkPermissions();
        if (perms.android !== 'granted') {
          await sqlitePlugin.requestPermissions();
        }
      } catch (e) {
        log('permission check skipped: ' + String(e));
      }

      connection = new SQLiteConnection(CapacitorSQLite);
      db = await connection.createConnection(DB_NAME, false, 'no-encryption', 1, false);
      await db.open();
      await migrate(db);
      log(`ready (v${DB_VERSION})`);
    } catch (e) {
      console.error('[db] init failed', e);
      throw e;
    }
  })();
  return initPromise;
}

async function migrate(db: SQLiteDBConnection): Promise<void> {
  const rows = await db.query('PRAGMA user_version');
  const current = Number(rows.values?.[0]?.user_version ?? 0);
  if (current >= DB_VERSION) {
    return;
  }
  log(`migrating schema ${current} → ${DB_VERSION}`);
  for (const stmt of SCHEMA_STATEMENTS) {
    await db.execute(stmt);
  }
  await db.execute(`PRAGMA user_version = ${DB_VERSION}`);
}

/** @throws if the database is not initialised. */
export async function getDb(): Promise<SQLiteDBConnection> {
  if (!db) {
    throw new Error('Database not initialised — call initDb() first.');
  }
  return db;
}

export interface SqliteRow {
  [key: string]: unknown;
}

/** SELECT — returns rows (empty array if none). */
export async function query<T = SqliteRow>(sql: string, params: unknown[] = []): Promise<T[]> {
  const conn = await getDb();
  const res = await conn.query(sql, params);
  return (res.values ?? []) as T[];
}

/** INSERT/UPDATE/DELETE — returns last insert id + changes. */
export async function run(
  sql: string,
  params: unknown[] = [],
): Promise<{ lastId: number; changes: number }> {
  const conn = await getDb();
  // The plugin wraps `run()` in its own transaction by default, which breaks
  // our explicit beginTransaction()/endTransaction() blocks (nested BEGIN →
  // "Already in transaction"). Atomicity for multi-statement writes is handled
  // by those explicit transactions; single statements are atomic on their own.
  const res = await conn.run(sql, params, false);
  return { lastId: res.changes?.lastId ?? 0, changes: res.changes?.changes ?? 0 };
}

/** Multiple independent statements (no params). */
export async function exec(sql: string): Promise<void> {
  const conn = await getDb();
  await conn.execute(sql);
}

/** Begin a transaction. Every beginTransaction MUST be paired with endTransaction. */
export async function beginTransaction(): Promise<void> {
  const conn = await getDb();
  await conn.beginTransaction();
}

export async function endTransaction(commit = true): Promise<void> {
  const conn = await getDb();
  if (commit) {
    await conn.commitTransaction();
  } else {
    await conn.rollbackTransaction();
  }
}

export async function closeDb(): Promise<void> {
  if (connection && db) {
    try {
      await connection.closeConnection(DB_NAME, false);
    } catch {
      // already closed
    }
  }
  db = null;
  connection = null;
  initPromise = null;
}

/** Test/`web` stub used outside native runtime. */
export function __setDbForTest(mockDb: SQLiteDBConnection): void {
  db = mockDb;
}
