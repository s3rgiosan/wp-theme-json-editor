import apiFetch from "@wordpress/api-fetch";
import type {
	HostAdapter,
	HostEvents,
	HostMode,
} from "@s3rgiosan/theme-json-editor-ui";

interface BootData {
	restNamespace: string;
	rootId: string;
	wpVersion: string;
	caps: { edit_themes: boolean; edit_theme_options: boolean };
	defaultMode: "theme" | "user";
}

interface DocumentResponse {
	data: Record<string, unknown>;
	etag: string;
	mode: "theme" | "user";
	path?: string;
	post_id?: number;
}

interface SchemaResponse {
	schema: Record<string, unknown>;
	snapshot: {
		generatedAt: string;
		wpVersion: string;
		experimental: string[];
		undocumented: string[];
	};
	schemaVersion: string;
}

interface ConflictBody {
	code: string;
	message: string;
	data: { status: number; server_etag: string; server_data: Record<string, unknown> };
}

/**
 * HostAdapter implementation backed by the WP REST API.
 *
 * Bootstraps by fetching `/document` and `/schema` in parallel; saves
 * via POST to `/document` with optimistic-concurrency etags. 409
 * responses are surfaced via `onConflict` so the existing
 * ConflictBanner renders.
 *
 * Exposes the two editing modes (`theme` / `user`) through the
 * `modes` / `getMode` / `setMode` HostAdapter hooks so the Toolbar
 * renders a switcher.
 */
export function createWpHost(boot: BootData): HostAdapter {
	let mode: "theme" | "user" = boot.defaultMode;
	let etag = "";
	let listener: HostEvents = {};
	let modeSwapInFlight = false;

	const path = (suffix: string) =>
		`/${boot.restNamespace}${suffix}`;

	async function loadDocument() {
		const doc = await apiFetch<DocumentResponse>({
			path: path(`/document?mode=${mode}`),
		});
		etag = doc.etag;
		listener.onInit?.(doc.data, doc.path ?? `wp_global_styles#${doc.post_id ?? 0}`);
	}

	async function loadSchema() {
		const bundle = await apiFetch<SchemaResponse>({
			path: path("/schema?version=auto"),
		});
		listener.onSchemaReady?.(bundle.schema, bundle.snapshot, bundle.schemaVersion);
	}

	async function bootstrap() {
		listener.onSettings?.({ showExperimental: false });
		await Promise.all([loadDocument(), loadSchema()]);
	}

	const buildModes = (): HostMode[] => {
		const list: HostMode[] = [
			{
				id: "theme",
				label: "Theme file",
				disabled: !boot.caps.edit_themes,
				disabledReason: boot.caps.edit_themes
					? undefined
					: "Requires the `edit_themes` capability and a writable theme.json.",
			},
		];

		// Only expose the user-styles mode when the server enabled it
		// via filter — boot.caps.edit_theme_options is gated by the
		// feature flag, not just the raw capability check.
		if (boot.caps.edit_theme_options) {
			list.push({
				id: "user",
				label: "User Global Styles",
				disabled: false,
			});
		}

		return list;
	};

	return {
		start(events) {
			listener = events;
			void bootstrap();
			return () => {
				listener = {};
			};
		},

		async save(data) {
			// A mode swap is in flight — the editor's currently
			// loaded data may not match the active mode yet. Drop
			// the save instead of writing a stale document.
			if (modeSwapInFlight) {
				console.warn("[wpHost] save skipped: mode swap in progress");
				return;
			}
			try {
				const res = await apiFetch<DocumentResponse>({
					path: path(`/document?mode=${mode}`),
					method: "POST",
					data: { data, etag, mode },
				});
				etag = res.etag;
				listener.onSaved?.();
			} catch (err) {
				const conflict = err as ConflictBody;
				if (conflict?.data?.status === 409) {
					etag = conflict.data.server_etag;
					listener.onConflict?.(conflict.data.server_data);
					return;
				}
				console.error("[wpHost] save failed", err);
			}
		},

		reportDirty() {
			// REST host doesn't track dirty state server-side. No-op.
		},

		modes: buildModes,

		getMode() {
			return mode;
		},

		async setMode(next) {
			if (next !== "theme" && next !== "user") {
				return;
			}
			if (next === mode || modeSwapInFlight) {
				return;
			}

			const previousMode = mode;
			const previousEtag = etag;
			mode = next;
			modeSwapInFlight = true;
			try {
				await loadDocument();
			} catch (err) {
				// Roll back so the UI's mode selector lines up with
				// the data the editor is actually showing.
				mode = previousMode;
				etag = previousEtag;
				console.error("[wpHost] setMode failed; reverted", err);
				throw err;
			} finally {
				modeSwapInFlight = false;
			}
		},
	};
}
