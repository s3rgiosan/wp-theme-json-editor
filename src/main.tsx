import { mountEditor } from "@s3rgiosan/theme-json-editor-ui";
import "@s3rgiosan/theme-json-editor-ui/style.css";
import { createWpHost } from "./wpHost";

declare global {
	interface Window {
		wpThemeJSONEditor?: {
			restNamespace: string;
			rootId: string;
			wpVersion: string;
			caps: { edit_themes: boolean; edit_theme_options: boolean };
			defaultMode: "theme" | "user";
		};
	}
}

const boot = window.wpThemeJSONEditor;

if (boot) {
	const root = document.getElementById(boot.rootId);
	if (root) {
		mountEditor(root, createWpHost(boot));
	} else {
		console.error(
			`[wp-theme-json-editor] root element #${boot.rootId} not found`,
		);
	}
} else {
	console.error("[wp-theme-json-editor] boot data missing on window");
}
