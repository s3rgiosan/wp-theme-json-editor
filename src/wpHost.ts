import apiFetch from "@wordpress/api-fetch";
import type {
	HostAdapter,
	HostEvents,
	HostMode,
} from "@s3rgiosan/theme-json-editor-ui";

interface ThemeFile {
	id: string;
	title: string;
	indent: boolean;
}

interface BootData {
	restNamespace: string;
	rootId: string;
	wpVersion: string;
	caps: { edit_themes: boolean; edit_theme_options: boolean };
	defaultMode: "theme" | "user";
	files: ThemeFile[];
}

interface DocumentResponse {
	data: Record<string, unknown>;
	etag: string;
	mode: string;
	path?: string;
	id?: string;
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

/** Sentinel target id for the user-level wp_global_styles document. */
const USER_TARGET = "user";

/**
 * HostAdapter implementation backed by the WP REST API.
 *
 * Bootstraps by fetching `/document` and `/schema` in parallel; saves
 * via POST to `/document` with optimistic-concurrency etags. 409
 * responses are surfaced via `onConflict` so the existing
 * ConflictBanner renders.
 *
 * Editing targets are addressed by id: every theme file reported in
 * `boot.files` (the root `theme.json` and its `styles/*.json` style
 * variations) plus, when enabled, the user-level global styles. The
 * `modes` / `getMode` / `setMode` HostAdapter hooks drive the editor's
 * file picker.
 */
export function createWpHost(boot: BootData): HostAdapter {
	let target =
		boot.defaultMode === USER_TARGET
			? USER_TARGET
			: (boot.files[0]?.id ?? "theme.json");
	let etag = "";
	let listener: HostEvents = {};
	let modeSwapInFlight = false;

	const path = (suffix: string) => `/${boot.restNamespace}${suffix}`;

	// Map a target id to the REST `mode`/`file` params. The user document
	// is a distinct mode; every other id is a theme file addressed by path.
	const requestParams = (id: string): { mode: string; file?: string } =>
		id === USER_TARGET ? { mode: USER_TARGET } : { mode: "file", file: id };

	const documentQuery = (id: string) => {
		const params = requestParams(id);
		const query = new URLSearchParams({ mode: params.mode });
		if (params.file !== undefined) {
			query.set("file", params.file);
		}
		return `/document?${query.toString()}`;
	};

	async function loadDocument() {
		const doc = await apiFetch<DocumentResponse>({
			path: path(documentQuery(target)),
		});
		etag = doc.etag;
		listener.onInit?.(
			doc.data,
			doc.path ?? `wp_global_styles#${doc.post_id ?? 0}`,
		);
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
		const list: HostMode[] = boot.files.map((file) => ({
			id: file.id,
			label: file.title,
			indent: file.indent,
			disabled: !boot.caps.edit_themes,
			disabledReason: boot.caps.edit_themes
				? undefined
				: "Requires the `edit_themes` capability and a writable theme file.",
		}));

		// Only expose the user-styles mode when the server enabled it
		// via filter — boot.caps.edit_theme_options is gated by the
		// feature flag, not just the raw capability check.
		if (boot.caps.edit_theme_options) {
			list.push({
				id: USER_TARGET,
				label: "User Global Styles",
				disabled: false,
			});
		}

		return list;
	};

	const isKnownTarget = (id: string) =>
		buildModes().some((mode) => mode.id === id && !mode.disabled);

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
			// loaded data may not match the active target yet. Drop
			// the save instead of writing a stale document.
			if (modeSwapInFlight) {
				console.warn("[wpHost] save skipped: mode swap in progress");
				return;
			}
			try {
				const res = await apiFetch<DocumentResponse>({
					path: path("/document"),
					method: "POST",
					data: { data, etag, ...requestParams(target) },
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
			return target;
		},

		async setMode(next) {
			if (next === target || modeSwapInFlight || !isKnownTarget(next)) {
				return;
			}

			const previousTarget = target;
			const previousEtag = etag;
			target = next;
			modeSwapInFlight = true;
			try {
				await loadDocument();
			} catch (err) {
				// Roll back so the UI's file picker lines up with
				// the data the editor is actually showing.
				target = previousTarget;
				etag = previousEtag;
				console.error("[wpHost] setMode failed; reverted", err);
				throw err;
			} finally {
				modeSwapInFlight = false;
			}
		},
	};
}
