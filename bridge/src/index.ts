#!/usr/bin/env node
/**
 * wp-mcp-bridge
 *
 * Copyright (C) 2026 Remy Mazmanian
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version. See the LICENSE file at the root of this repository.
 *
 * A stdio-to-HTTP proxy for the WP MCP Connector.
 *
 * Some MCP clients only speak stdio, and some that do speak HTTP make it
 * awkward to attach an Authorization header. This bridge sits between them and
 * the WordPress endpoint: it reads newline-delimited JSON-RPC from stdin, POSTs
 * each message to the site's Streamable HTTP endpoint with credentials
 * attached, and writes whatever comes back to stdout.
 *
 * It is deliberately dependency-free. A proxy has to be transparent, and the
 * fewer moving parts between the client and the site the fewer ways there are
 * for a message to be mangled. Node 18+ provides everything needed.
 *
 * Usage:
 *   WP_MCP_URL=https://example.com/wp-json/mcp/v1/mcp \
 *   WP_MCP_USERNAME=admin \
 *   WP_MCP_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx" \
 *   wp-mcp-bridge
 *
 * Or with a Bearer token:
 *   WP_MCP_URL=... WP_MCP_TOKEN=wpmcp_... wp-mcp-bridge
 *
 * Better still on macOS, keep the secret out of every config file by putting it
 * in the login keychain once:
 *
 *   wp-mcp-bridge --save-credential --url https://example.com --user admin
 *
 * After that only the URL and username need to appear anywhere on disk.
 *
 * Flags:
 *   --probe              Check the connection and print a summary, then exit.
 *   --save-credential    Store the secret in the macOS keychain.
 *   --delete-credential  Remove it again.
 *   --verbose            Log every message to stderr.
 */

import { execFileSync, spawnSync } from 'node:child_process';

/* ------------------------------------------------------------------ *
 * Types
 * ------------------------------------------------------------------ */

interface JsonRpcMessage {
	jsonrpc?: string;
	id?: string | number | null;
	method?: string;
	params?: unknown;
	result?: unknown;
	error?: { code: number; message: string; data?: unknown };
}

interface Config {
	url: string;
	username?: string;
	appPassword?: string;
	token?: string;
	verbose: boolean;
	probe: boolean;
	saveCredential: boolean;
	deleteCredential: boolean;
	timeoutMs: number;
}

/* ------------------------------------------------------------------ *
 * Configuration
 * ------------------------------------------------------------------ */

/**
 * Reads configuration from flags first, then the environment.
 *
 * Flags win so that a single global install can serve several sites from
 * different client config entries.
 */
function readConfig(argv: string[]): Config {
	const flag = (name: string): string | undefined => {
		const withEquals = argv.find((a) => a.startsWith(`--${name}=`));
		if (withEquals) return withEquals.slice(name.length + 3);

		const index = argv.indexOf(`--${name}`);
		if (index !== -1 && argv[index + 1] && !argv[index + 1].startsWith('--')) {
			return argv[index + 1];
		}

		return undefined;
	};

	const url = flag('url') ?? process.env.WP_MCP_URL ?? '';

	return {
		url: normalizeUrl(url),
		username: flag('user') ?? process.env.WP_MCP_USERNAME,
		appPassword: flag('password') ?? process.env.WP_MCP_APP_PASSWORD,
		token: flag('token') ?? process.env.WP_MCP_TOKEN,
		verbose: argv.includes('--verbose') || process.env.WP_MCP_VERBOSE === '1',
		probe: argv.includes('--probe'),
		saveCredential: argv.includes('--save-credential'),
		deleteCredential: argv.includes('--delete-credential'),
		timeoutMs: Number(process.env.WP_MCP_TIMEOUT_MS ?? 60_000),
	};
}

/**
 * Accepts a bare site URL and fills in the endpoint path, so that
 * `https://example.com` and the full REST route both work.
 */
function normalizeUrl(raw: string): string {
	if (!raw) return '';

	let url = raw.trim().replace(/\/+$/, '');

	if (!/^https?:\/\//i.test(url)) {
		url = `https://${url}`;
	}

	if (!url.includes('/wp-json/') && !url.includes('rest_route=')) {
		url = `${url}/wp-json/mcp/v1/mcp`;
	}

	return url;
}

/* ------------------------------------------------------------------ *
 * Keychain
 * ------------------------------------------------------------------ */

/**
 * Keychain service name. All entries created by this bridge share it, so
 * `security dump-keychain` and Keychain Access both group them together.
 */
const KEYCHAIN_SERVICE = 'wp-mcp-bridge';

/**
 * Whether the macOS keychain is usable on this machine.
 */
function keychainAvailable(): boolean {
	return process.platform === 'darwin';
}

/**
 * The keychain account name for a configuration.
 *
 * Scoped to host and username so one machine can hold credentials for several
 * sites, and for several users on the same site, without collision.
 */
function keychainAccount(config: Config): string {
	let host = config.url;

	try {
		host = new URL(config.url).host;
	} catch {
		/* Fall back to the raw string; it still discriminates between sites. */
	}

	return `${config.username ?? 'bearer'}@${host}`;
}

/**
 * Reads a secret from the keychain.
 *
 * Returns null rather than throwing when the item is absent, since "no stored
 * credential" is an ordinary state, not an error.
 */
function readKeychain(account: string): string | null {
	if (!keychainAvailable()) return null;

	try {
		const value = execFileSync(
			'security',
			['find-generic-password', '-s', KEYCHAIN_SERVICE, '-a', account, '-w'],
			{ encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }
		);

		return value.trim() || null;
	} catch {
		return null;
	}
}

/**
 * Fills in a missing secret from the keychain.
 *
 * Explicit environment variables and flags win, so an existing setup keeps
 * working unchanged and CI can inject a credential without touching a keychain.
 */
function resolveSecret(config: Config): Config {
	if (config.token || config.appPassword) {
		return config;
	}

	const stored = readKeychain(keychainAccount(config));

	if (!stored) {
		return config;
	}

	// A stored value is a Bearer token if it carries the plugin's token prefix,
	// and an Application Password otherwise.
	if (stored.startsWith('wpmcp_')) {
		return { ...config, token: stored };
	}

	return { ...config, appPassword: stored };
}

/**
 * Stores a secret in the keychain.
 *
 * The value is never handled by this process: `security` is spawned with the
 * terminal attached and does its own no-echo prompt, so the secret reaches the
 * keychain without passing through argv (visible in `ps`), the environment, a
 * Node string, or shell history.
 */
function saveCredential(config: Config): never {
	if (!keychainAvailable()) {
		fail('--save-credential needs the macOS keychain. On Linux or Windows, use environment variables in your client config instead.');
	}

	if (!config.username && !process.env.WP_MCP_BEARER_ACCOUNT) {
		fail('Pass --user USERNAME (for an Application Password) so the credential can be labelled. For a Bearer token, pass --user bearer.');
	}

	const account = keychainAccount(config);

	process.stderr.write(
		`Storing a credential for ${account}.\n` +
			'Paste the Application Password (or Bearer token) at the prompt. It is not echoed, and you will be asked twice.\n\n'
	);

	const result = spawnSync(
		'security',
		['add-generic-password', '-s', KEYCHAIN_SERVICE, '-a', account, '-U', '-w'],
		{ stdio: 'inherit' }
	);

	if (result.status !== 0) {
		fail('The keychain refused to store that credential.');
	}

	process.stderr.write(
		`\nStored. Your client config now needs only:\n` +
			`  WP_MCP_URL=${config.url}\n` +
			(config.username ? `  WP_MCP_USERNAME=${config.username}\n` : '') +
			`\nVerify with:  wp-mcp-bridge --probe --url ${config.url}` +
			(config.username ? ` --user ${config.username}\n` : '\n')
	);

	process.exit(0);
}

/**
 * Removes a stored secret.
 */
function deleteCredential(config: Config): never {
	if (!keychainAvailable()) {
		fail('--delete-credential needs the macOS keychain.');
	}

	const account = keychainAccount(config);

	const result = spawnSync(
		'security',
		['delete-generic-password', '-s', KEYCHAIN_SERVICE, '-a', account],
		{ stdio: ['ignore', 'ignore', 'ignore'] }
	);

	process.stderr.write(
		result.status === 0
			? `Removed the stored credential for ${account}.\n`
			: `No stored credential for ${account}.\n`
	);

	process.exit(0);
}

/**
 * Builds the auth header, preferring an explicit Bearer token.
 */
function authHeader(config: Config): string | null {
	if (config.token) {
		return `Bearer ${config.token}`;
	}

	if (config.username && config.appPassword) {
		// Application Passwords are displayed with spaces for readability;
		// WordPress ignores them, but stripping keeps the encoded value tidy.
		const password = config.appPassword.replace(/\s+/g, '');
		return `Basic ${Buffer.from(`${config.username}:${password}`).toString('base64')}`;
	}

	return null;
}

/* ------------------------------------------------------------------ *
 * Output
 * ------------------------------------------------------------------ */

/** stdout carries protocol traffic only, so all logging goes to stderr. */
function log(config: Config, ...parts: unknown[]): void {
	if (config.verbose) {
		process.stderr.write(`[wp-mcp-bridge] ${parts.map(String).join(' ')}\n`);
	}
}

function fail(message: string): never {
	process.stderr.write(`[wp-mcp-bridge] ${message}\n`);
	process.exit(1);
}

function write(message: JsonRpcMessage): void {
	process.stdout.write(`${JSON.stringify(message)}\n`);
}

/* ------------------------------------------------------------------ *
 * Transport
 * ------------------------------------------------------------------ */

class Bridge {
	private sessionId: string | null = null;

	constructor(private readonly config: Config) {}

	/**
	 * Sends one message upstream and emits whatever comes back.
	 */
	async forward(message: JsonRpcMessage): Promise<void> {
		const id = message.id ?? null;

		try {
			const response = await this.post(message);

			// The server hands out a session id on initialize; every later
			// request has to carry it back.
			const session = response.headers.get('mcp-session-id');
			if (session) {
				this.sessionId = session;
			}

			if (response.status === 202 || response.status === 204) {
				return; // Notification accepted; nothing to reply with.
			}

			if (!response.ok) {
				await this.emitHttpError(response, id);
				return;
			}

			const contentType = response.headers.get('content-type') ?? '';
			const body = await response.text();

			if (!body.trim()) {
				return;
			}

			if (contentType.includes('text/event-stream')) {
				for (const payload of parseSse(body)) {
					this.emit(payload);
				}
				return;
			}

			this.emit(body);
		} catch (error) {
			const reason = error instanceof Error ? error.message : String(error);
			log(this.config, 'request failed:', reason);

			// A transport failure still needs an answer, or the client hangs on
			// a request that will never be resolved.
			if (id !== null) {
				write({
					jsonrpc: '2.0',
					id,
					error: {
						code: -32003,
						message: `Could not reach the WordPress MCP endpoint: ${reason}`,
					},
				});
			}
		}
	}

	/** Emits a parsed response body, which may be a single message or a batch. */
	private emit(raw: string): void {
		let parsed: unknown;

		try {
			parsed = JSON.parse(raw);
		} catch {
			log(this.config, 'discarding unparseable response:', raw.slice(0, 200));
			return;
		}

		if (Array.isArray(parsed)) {
			for (const item of parsed) {
				write(item as JsonRpcMessage);
			}
			return;
		}

		write(parsed as JsonRpcMessage);
	}

	/** Turns an HTTP failure into a JSON-RPC error the client can display. */
	private async emitHttpError(response: Response, id: string | number | null): Promise<void> {
		const body = await response.text();
		let message = `WordPress returned HTTP ${response.status}.`;

		try {
			const parsed = JSON.parse(body);

			// A JSON-RPC error came back with a non-2xx status: pass it straight
			// through rather than wrapping it in a second error.
			if (parsed && typeof parsed === 'object' && 'jsonrpc' in parsed) {
				write(parsed as JsonRpcMessage);
				return;
			}

			if (parsed?.message) {
				message = parsed.message;
			}
		} catch {
			/* Body was not JSON; the status-based message stands. */
		}

		if (response.status === 401) {
			message += ' Check WP_MCP_USERNAME and WP_MCP_APP_PASSWORD, and that Application Passwords are enabled for that user.';
		} else if (response.status === 403) {
			message += ' The credentials worked but the account lacks the capability this site requires.';
		} else if (response.status === 404 && this.sessionId) {
			// The session expired. Dropping it lets the next initialize succeed.
			this.sessionId = null;
			message += ' The session expired; the client should re-initialize.';
		} else if (response.status === 503) {
			message += ' The MCP server is switched off in the plugin settings.';
		}

		if (id !== null) {
			write({ jsonrpc: '2.0', id, error: { code: -32004, message } });
		} else {
			log(this.config, message);
		}
	}

	/** Performs the upstream POST. */
	private async post(message: JsonRpcMessage): Promise<Response> {
		const headers: Record<string, string> = {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
			'User-Agent': 'wp-mcp-bridge/1.0',
		};

		const auth = authHeader(this.config);
		if (auth) {
			headers.Authorization = auth;
		}

		if (this.sessionId) {
			headers['Mcp-Session-Id'] = this.sessionId;
		}

		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), this.config.timeoutMs);

		try {
			return await fetch(this.config.url, {
				method: 'POST',
				headers,
				body: JSON.stringify(message),
				signal: controller.signal,
			});
		} finally {
			clearTimeout(timer);
		}
	}
}

/**
 * Extracts the data payloads from an SSE body.
 */
function parseSse(body: string): string[] {
	const payloads: string[] = [];

	for (const block of body.split(/\r?\n\r?\n/)) {
		const data = block
			.split(/\r?\n/)
			.filter((line) => line.startsWith('data:'))
			.map((line) => line.slice(5).trimStart())
			.join('\n');

		if (data) {
			payloads.push(data);
		}
	}

	return payloads;
}

/* ------------------------------------------------------------------ *
 * Probe
 * ------------------------------------------------------------------ */

/**
 * Checks the connection and prints a human summary. Run this first when a
 * client says only "server failed to start".
 */
async function probe(config: Config): Promise<void> {
	const healthUrl = config.url.replace(/\/mcp$/, '/health');
	const headers: Record<string, string> = { Accept: 'application/json' };
	const auth = authHeader(config);

	if (auth) {
		headers.Authorization = auth;
	}

	process.stderr.write(`Checking ${healthUrl}\n`);

	const response = await fetch(healthUrl, { headers });
	const body = await response.text();

	if (!response.ok) {
		process.stderr.write(`HTTP ${response.status}\n${body}\n`);
		process.exit(1);
	}

	const health = JSON.parse(body);

	process.stderr.write(
		[
			`Connected to: ${health.site?.name} (${health.site?.url})`,
			`WordPress:    ${health.wordpress} on PHP ${health.php}`,
			`Plugin:       ${health.version}`,
			`Authenticated as: ${health.auth?.user} (${(health.auth?.roles ?? []).join(', ')}) via ${health.auth?.method}`,
			`Profile:      ${health.profile}`,
			`Tools:        ${(health.toolsAvailable ?? []).length} of ${health.toolsTotal} available`,
			`Abilities API: ${health.abilitiesApi ? 'yes' : 'no'}`,
			'',
			(health.toolsAvailable ?? []).join(', '),
			'',
		].join('\n')
	);
}

/* ------------------------------------------------------------------ *
 * Main
 * ------------------------------------------------------------------ */

async function main(): Promise<void> {
	let config = readConfig(process.argv.slice(2));

	if (!config.url) {
		fail('Set WP_MCP_URL (or pass --url) to your site, for example https://example.com/wp-json/mcp/v1/mcp');
	}

	if (config.saveCredential) {
		saveCredential(config);
	}

	if (config.deleteCredential) {
		deleteCredential(config);
	}

	// Fall back to the keychain before giving up on credentials.
	config = resolveSecret(config);

	if (!authHeader(config)) {
		fail(
			'No credentials. Either set WP_MCP_USERNAME and WP_MCP_APP_PASSWORD (or WP_MCP_TOKEN), ' +
				`or store the secret once with:\n  wp-mcp-bridge --save-credential --url ${config.url} --user YOUR_USERNAME`
		);
	}

	if (config.probe) {
		await probe(config);
		return;
	}

	log(config, 'proxying to', config.url);

	const bridge = new Bridge(config);
	let buffer = '';

	// Messages are processed one at a time. MCP allows concurrency, but strict
	// ordering costs little at this volume and removes a whole class of
	// interleaving bug.
	let queue: Promise<void> = Promise.resolve();

	process.stdin.setEncoding('utf8');

	process.stdin.on('data', (chunk: string) => {
		buffer += chunk;

		let newline = buffer.indexOf('\n');

		while (newline !== -1) {
			const line = buffer.slice(0, newline).trim();
			buffer = buffer.slice(newline + 1);
			newline = buffer.indexOf('\n');

			if (!line) continue;

			let message: JsonRpcMessage;

			try {
				message = JSON.parse(line);
			} catch {
				log(config, 'ignoring non-JSON line from client:', line.slice(0, 200));
				continue;
			}

			log(config, '→', message.method ?? `response ${message.id}`);
			queue = queue.then(() => bridge.forward(message));
		}
	});

	process.stdin.on('end', () => {
		queue.finally(() => process.exit(0));
	});
}

main().catch((error) => {
	fail(error instanceof Error ? error.message : String(error));
});
