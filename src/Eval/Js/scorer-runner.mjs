// Runs a Braintrust-convention scorer file locally: the file defines
// `function handler({ output, input, expected })` (no export), we feed the
// payload from stdin and print the result as JSON. Mirrors how Braintrust's
// sandbox calls the same file once published.
import { readFileSync } from "node:fs";

const file = process.argv[2];
const source = readFileSync(file, "utf8");
const payload = JSON.parse(readFileSync(0, "utf8"));

const handler = (0, eval)(`${source}\n;handler`);

if (typeof handler !== "function") {
  console.error("Scorer file must define function handler({ output, input, expected })");
  process.exit(1);
}

const result = await handler(payload);
const normalized = typeof result === "number" ? { score: result } : result;

process.stdout.write(JSON.stringify(normalized ?? {}));
