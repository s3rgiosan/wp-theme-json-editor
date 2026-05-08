import { defineConfig, type Plugin } from "vite";
import react from "@vitejs/plugin-react";
import { createRequire } from "node:module";
import { resolve } from "node:path";
import { mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import postcss from "postcss";
import cssnano from "cssnano";

const require = createRequire(import.meta.url);

/**
 * Mirror the linked package's schema assets into the plugin's
 * `build/` directory so PHP can read them from a stable path. The
 * package is the single source of truth — this runs on every build
 * to keep the copy in sync.
 */
function copySchemaAssetsPlugin(): Plugin {
	return {
		name: "wp-tje-copy-schema-assets",
		async closeBundle() {
			const pkg = "@s3rgiosan/theme-json-editor-ui";
			const targets = [
				`${pkg}/assets/core-scan-snapshot.json`,
				`${pkg}/assets/theme.json.fallback`,
			];
			const outDir = resolve(__dirname, "build");
			mkdirSync(outDir, { recursive: true });
			for (const spec of targets) {
				const src = require.resolve(spec);
				const dest = resolve(outDir, spec.split("/").pop()!);
				writeFileSync(dest, readFileSync(src));
			}
		},
	};
}

/**
 * Minify the static admin-theme stylesheet via cssnano and emit it
 * alongside a WP-style `.asset.php` descriptor (with version derived
 * from the source file's mtime).
 */
function adminStylesheetPlugin(): Plugin {
	return {
		name: "wp-tje-admin-stylesheet",
		async closeBundle() {
			const sourcePath = resolve(__dirname, "src/admin.css");
			const source = readFileSync(sourcePath, "utf8");
			const minified = await postcss([cssnano({ preset: "default" })])
				.process(source, { from: undefined });
			const outDir = resolve(__dirname, "build");
			mkdirSync(outDir, { recursive: true });
			writeFileSync(resolve(outDir, "admin.css"), minified.css);

			const version = Math.floor(statSync(sourcePath).mtimeMs).toString();
			const php = `<?php return array('dependencies' => array('wp-components'), 'version' => '${version}');\n`;
			writeFileSync(resolve(outDir, "admin-style.asset.php"), php);
		},
	};
}

/**
 * Emit a WP-style `main.asset.php` so PHP can read the JS bundle's
 * dependencies + version hash.
 */
function adminScriptAssetPhpPlugin(): Plugin {
	return {
		name: "wp-tje-admin-script-asset-php",
		closeBundle() {
			const deps = ["wp-api-fetch", "wp-i18n", "wp-components"];
			const version = Date.now().toString();
			const php = `<?php return array('dependencies' => array(${deps
				.map((d) => `'${d}'`)
				.join(", ")}), 'version' => '${version}');\n`;
			writeFileSync(resolve(__dirname, "build/admin.asset.php"), php);
		},
	};
}

/**
 * Vite config for the plugin's admin React app.
 */
export default defineConfig({
	plugins: [
		react(),
		copySchemaAssetsPlugin(),
		adminStylesheetPlugin(),
		adminScriptAssetPhpPlugin(),
	],
	build: {
		outDir: "build",
		emptyOutDir: true,
		manifest: false,
		rollupOptions: {
			input: resolve(__dirname, "src/main.tsx"),
			output: {
				entryFileNames: "admin.js",
				assetFileNames: "admin[extname]",
				format: "iife",
				globals: {
					"@wordpress/api-fetch": "wp.apiFetch",
					"@wordpress/i18n": "wp.i18n",
					"@wordpress/components": "wp.components",
				},
			},
			external: [
				"@wordpress/api-fetch",
				"@wordpress/i18n",
				"@wordpress/components",
			],
		},
	},
});
