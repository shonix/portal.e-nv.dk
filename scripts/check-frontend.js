"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

const repoRoot = path.resolve(__dirname, "..");
const rootFiles = fs.readdirSync(repoRoot, { withFileTypes: true });
const javascriptFiles = rootFiles
  .filter((entry) => entry.isFile() && entry.name.endsWith(".js"))
  .map((entry) => entry.name)
  .sort();
const htmlFiles = rootFiles
  .filter((entry) => entry.isFile() && entry.name.endsWith(".html"))
  .map((entry) => entry.name)
  .sort();

let inlineScriptCount = 0;

// Compile root JavaScript files without executing browser-dependent code.
for (const file of javascriptFiles) {
  const source = fs.readFileSync(path.join(repoRoot, file), "utf8");
  new vm.Script(source, { filename: file });
}

// Compile inline scripts while ignoring external <script src="..."> tags.
const scriptPattern = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
for (const file of htmlFiles) {
  const html = fs.readFileSync(path.join(repoRoot, file), "utf8");
  for (const match of html.matchAll(scriptPattern)) {
    const attributes = match[1];
    const source = match[2].trim();
    if (/\bsrc\s*=/i.test(attributes) || source === "") continue;
    inlineScriptCount += 1;
    new vm.Script(source, { filename: `${file}:inline-${inlineScriptCount}` });
  }
}

console.log(
  `Parsed ${javascriptFiles.length} JavaScript files and ` +
    `${inlineScriptCount} inline scripts from ${htmlFiles.length} HTML files.`
);

