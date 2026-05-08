import js from "@eslint/js";
import tsPlugin from "typescript-eslint";

export default tsPlugin.config(
	{
		ignores: ["build/**", "node_modules/**", "vendor/**"],
	},
	js.configs.recommended,
	tsPlugin.configs.recommended,
	{
		files: ["**/*.ts", "**/*.tsx"],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: "module",
			globals: {
				window: "readonly",
				document: "readonly",
				console: "readonly",
			},
		},
		rules: {
			"@typescript-eslint/no-unused-vars": [
				"error",
				{ argsIgnorePattern: "^_" },
			],
		},
	},
);
