import ivm from 'isolated-vm';
import { parse } from 'acorn';

/**
 * Normalize user code using acorn AST analysis:
 * - Arrow function expression → pass through as-is
 * - Last statement is an expression → prepend `return` and wrap in async IIFE
 * - Last statement is declaration/control flow → wrap without return
 * - Parse failure → wrap as-is (let the sandbox report the error)
 */
function normalizeCode(code) {
  const trimmed = code.trim();

  let ast;
  try {
    ast = parse(trimmed, { ecmaVersion: 'latest', sourceType: 'module', allowReturnOutsideFunction: true });
  } catch {
    // Parse as expression (e.g. bare arrow function)
    try {
      ast = parse(`(${trimmed})`, { ecmaVersion: 'latest', sourceType: 'module', allowReturnOutsideFunction: true });
      if (
        ast.body.length === 1 &&
        ast.body[0].type === 'ExpressionStatement' &&
        ast.body[0].expression.type === 'ArrowFunctionExpression'
      ) {
        return trimmed;
      }
    } catch {
      // Both attempts failed — return as-is, let sandbox report the real error
    }
    return `return ${trimmed}`;
  }

  if (ast.body.length === 0) {
    return trimmed;
  }

  if (
    ast.body.length === 1 &&
    ast.body[0].type === 'ExpressionStatement' &&
    ast.body[0].expression.type === 'ArrowFunctionExpression'
  ) {
    return trimmed;
  }

  const lastStmt = ast.body[ast.body.length - 1];

  if (lastStmt.type === 'ExpressionStatement') {
    const before = trimmed.slice(0, lastStmt.start);
    const lastExpr = trimmed.slice(lastStmt.start);
    return before + 'return ' + lastExpr;
  }

  return trimmed;
}

// --- Read input from stdin ---
let input;
try {
  input = await new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => data += chunk);
    process.stdin.on('end', () => {
      try {
        resolve(JSON.parse(data));
      } catch (e) {
        reject(new Error(`Invalid JSON input: ${e.message}`));
      }
    });
  });
} catch (e) {
  process.stdout.write(JSON.stringify({ success: false, error: e.message, logs: [] }));
  process.exit(0);
}

const {
  code,
  context = {},
  apiBaseUrl = null,
  headers: extraHeaders = {},
  apiPrefix = null,
  memoryLimit = 64,
} = input;

const isolate = new ivm.Isolate({ memoryLimit });
const ivmContext = await isolate.createContext();

const jail = ivmContext.global;
await jail.set('global', jail.derefInto());

// Inject context variables
for (const [key, value] of Object.entries(context)) {
  await jail.set(key, new ivm.ExternalCopy(value).copyInto());
}

// Collect logs
const logs = [];
await jail.set('__pushLog', new ivm.Reference((...args) => {
  logs.push(args.map(a => typeof a === 'string' ? a : JSON.stringify(a)).join(' '));
}));
await ivmContext.eval(`
  const console = {
    log: (...args) => __pushLog.applySync(undefined, args.map(a => typeof a === 'object' ? JSON.stringify(a) : String(a))),
    error: (...args) => __pushLog.applySync(undefined, args.map(a => typeof a === 'object' ? JSON.stringify(a) : String(a))),
    warn: (...args) => __pushLog.applySync(undefined, args.map(a => typeof a === 'object' ? JSON.stringify(a) : String(a))),
  };

  /**
   * Pick specific keys from an object.
   * pick({ a: 1, b: 2, c: 3 }, ['a', 'c']) → { a: 1, c: 3 }
   */
  function pick(obj, keys) {
    if (!obj || typeof obj !== 'object') return obj;
    const result = {};
    for (const k of keys) {
      if (k in obj) result[k] = obj[k];
    }
    return result;
  }

  /**
   * Pick keys from every item in an array.
   * pluck([{ a: 1, b: 2 }, { a: 3, b: 4 }], ['a']) → [{ a: 1 }, { a: 3 }]
   */
  function pluck(arr, keys) {
    if (!Array.isArray(arr)) return arr;
    return arr.map(item => pick(item, keys));
  }
`);

// Inject api() function if apiBaseUrl is provided
if (apiBaseUrl) {
  const baseHeaders = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...extraHeaders,
  };

  await jail.set('__apiCall', new ivm.Reference(async (method, path, dataJson) => {
    // Auto-prefix: if apiPrefix is set and path doesn't already start with it
    if (apiPrefix && !path.startsWith(apiPrefix)) {
      path = apiPrefix + (path.startsWith('/') ? '' : '/') + path;
    }

    const url = `${apiBaseUrl}${path.startsWith('/') ? '' : '/'}${path}`;
    const opts = {
      method: method.toUpperCase(),
      headers: { ...baseHeaders },
    };

    if (dataJson && !['GET', 'HEAD'].includes(opts.method)) {
      opts.body = dataJson;
    }

    const res = await fetch(url, opts);
    const body = await res.text();

    try {
      const json = JSON.parse(body);
      // Strip stack traces from error responses
      if (json.exception) {
        return new ivm.ExternalCopy({ error: json.message, status: res.status }).copyInto();
      }
      return new ivm.ExternalCopy(json).copyInto();
    } catch {
      return new ivm.ExternalCopy({ raw: body, status: res.status }).copyInto();
    }
  }));

  await ivmContext.eval(`
    function api(method, path, data) {
      const dataJson = data !== undefined ? JSON.stringify(data) : undefined;
      return new Promise((resolve, reject) => {
        try {
          const result = __apiCall.applySyncPromise(undefined, [method, path, dataJson]);
          resolve(result);
        } catch (e) {
          reject(e);
        }
      });
    }
  `);
}

try {
  const normalizedCode = normalizeCode(code);
  const wrappedCode = `
    (async () => {
      ${normalizedCode}
    })()
  `;

  const result = await ivmContext.eval(wrappedCode, { timeout: 10000, promise: true, copy: true });

  const output = { success: true, result: result ?? null, logs };
  process.stdout.write(JSON.stringify(output));
} catch (err) {
  const output = { success: false, error: err.message, logs };
  process.stdout.write(JSON.stringify(output));
} finally {
  isolate.dispose();
}
