import { parse } from "@babel/parser";
import { readFileSync, readdirSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = fileURLToPath(new URL("..", import.meta.url));
const pluginRoot = join(repoRoot, "plugin");
const ignoredDirectories = new Set(["node_modules", "vendor"]);

function javascriptFiles(directory) {
  const files = [];

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!ignoredDirectories.has(entry.name)) {
        files.push(...javascriptFiles(join(directory, entry.name)));
      }
      continue;
    }

    if (entry.isFile() && entry.name.endsWith(".js")) {
      files.push(join(directory, entry.name));
    }
  }

  return files;
}

const files = javascriptFiles(pluginRoot).sort();
const failures = [];

for (const file of files) {
  try {
    parse(readFileSync(file, "utf8"), {
      sourceType: "unambiguous",
      allowAwaitOutsideFunction: true,
      allowReturnOutsideFunction: true,
      plugins: [
        "jsx",
        "classProperties",
        "classPrivateProperties",
        "classPrivateMethods",
        "decorators-legacy",
        "dynamicImport",
        "importMeta",
        "logicalAssignment",
        "nullishCoalescingOperator",
        "optionalChaining",
        "objectRestSpread",
        "topLevelAwait",
      ],
    });
  } catch (error) {
    failures.push(`${relative(repoRoot, file)}: ${error.message}`);
  }
}

if (failures.length > 0) {
  console.error("JavaScript/JSX syntax check failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exitCode = 1;
} else {
  console.log(`JavaScript/JSX syntax check passed for ${files.length} files.`);
}
