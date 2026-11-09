// AST ranges keep compiled and source JS byte-identical outside domain literals.
const fs = require('node:fs');
const { parse } = require('@babel/parser');
const traverse = require('@babel/traverse').default;
const source = fs.readFileSync(0, 'utf8');
const ast = parse(source, { sourceType: 'unambiguous', plugins: ['jsx'] });
const domains = { __: 1, _x: 2, _n: 3, _nx: 4 };
const identities = new Map();
const declarations = [];
// Babel owns lexical scope, hoisting, loop bindings and local class/function names.
traverse(ast, {
  VariableDeclarator(p) { declarations.push(p); },
  ImportDeclaration(p) {
    if (p.node.source.value !== '@wordpress/i18n') return;
    for (const spec of p.get('specifiers')) {
      const binding = spec.scope.getBinding(spec.node.local.name);
      if (spec.isImportNamespaceSpecifier()) identities.set(binding, { kind: 'namespace' });
      if (spec.isImportSpecifier()) {
        const name = spec.node.imported.name || spec.node.imported.value;
        if (Object.hasOwn(domains, name)) identities.set(binding, { kind: 'function', name });
      }
    }
  },
});
function unwrap(p) {
  while (p?.isSequenceExpression()) p = p.get('expressions').at(-1);
  return p;
}
function identity(p) {
  p = unwrap(p);
  if (!p?.node) return null;
  if (p.isIdentifier()) {
    const binding = p.scope.getBinding(p.node.name);
    if (binding) return identities.get(binding);
    if (p.node.name === 'wp') return { kind: 'wp' };
    if (p.node.name === 'window') return { kind: 'window' };
    return null;
  }
  if (p.isMemberExpression() || p.isOptionalMemberExpression()) {
    const object = identity(p.get('object'));
    if (!object) return null;
    const prop = p.get('property');
    const name = !p.node.computed && prop.isIdentifier() ? prop.node.name :
      prop.isStringLiteral() ? prop.node.value : null;
    if (name === null) throw new Error('Unsupported computed translation namespace/member');
    if (object.kind === 'window' && name === 'wp') return { kind: 'wp' };
    if (object.kind === 'wp' && name === 'i18n') return { kind: 'namespace' };
    if (object.kind === 'namespace' && Object.hasOwn(domains, name)) return { kind: 'function', name };
  }
  return null;
}
// Binding identities converge independently of declaration order. Do not erase
// identities on writes: mutation must fail before any edit can be emitted.
let changed;
do {
  changed = false;
  for (const p of declarations) {
    const value = identity(p.get('init'));
    if (!value) continue;
    const id = p.get('id');
    if (!id.isIdentifier()) throw new Error('Unsupported translation alias pattern');
    const binding = id.scope.getBinding(id.node.name);
    const previous = identities.get(binding);
    if (previous && (previous.kind !== value.kind || previous.name !== value.name)) {
      throw new Error('Ambiguous translation alias redeclaration');
    }
    if (!previous) { identities.set(binding, value); changed = true; }
  }
} while (changed);
for (const binding of identities.keys()) {
  if (!binding || binding.constantViolations.length) {
    throw new Error('Ambiguous translation alias reassignment/redeclaration');
  }
}
function affectsNamespace(p) {
  if (!p?.node) return false;
  if (p.isObjectPattern()) return p.get('properties').some(prop =>
    affectsNamespace(prop.isRestElement() ? prop.get('argument') : prop.get('value')));
  if (p.isArrayPattern()) return p.get('elements').some(affectsNamespace);
  if (p.isRestElement()) return affectsNamespace(p.get('argument'));
  if (p.isAssignmentPattern()) return affectsNamespace(p.get('left'));
  if (p.isIdentifier()) return !!identity(p);
  // const bindings do not track object-member writes. Check namespace/root
  // objects explicitly, including computed targets, loops and destructuring.
  if (p.isMemberExpression() || p.isOptionalMemberExpression()) {
    const object = identity(p.get('object'));
    if (!object) return false;
    if (object.kind === 'namespace' || object.kind === 'function') return true;
    const prop = p.get('property');
    const name = !p.node.computed && prop.isIdentifier() ? prop.node.name :
      prop.isStringLiteral() ? prop.node.value : null;
    // Unknown computed root writes may replace wp/i18n; unrelated named
    // window properties (Chart, UI exports) cannot change those identities.
    return name === null || (object.kind === 'window' && name === 'wp') ||
      (object.kind === 'wp' && name === 'i18n');
  }
  return false;
}
traverse(ast, {
  AssignmentExpression(p) {
    if (affectsNamespace(p.get('left'))) throw new Error('Ambiguous write to translation namespace/member');
    if (identity(p.get('right'))) throw new Error('Unsupported assignment of translation alias');
  },
  UpdateExpression(p) {
    if (affectsNamespace(p.get('argument'))) throw new Error('Ambiguous write to translation namespace/member');
  },
  UnaryExpression(p) {
    if (p.node.operator === 'delete' && affectsNamespace(p.get('argument'))) {
      throw new Error('Ambiguous write to translation namespace/member');
    }
  },
  'ForInStatement|ForOfStatement'(p) {
    const left = p.get('left');
    if (!left.isVariableDeclaration() && affectsNamespace(left)) {
      throw new Error('Ambiguous loop write to translation namespace/member');
    }
  },
});
const edits = [];
traverse(ast, {
  'CallExpression|OptionalCallExpression'(p) {
    const value = identity(p.get('callee'));
    if (value?.kind !== 'function') return;
    const name = value.name;
    const arg = p.get('arguments')[domains[name]];
    if (!arg) return;
    if (!arg.isStringLiteral()) {
      let legacy = false;
      if (arg.node?.value === 'subscription') legacy = true;
      arg.traverse({ StringLiteral(child) { if (child.node.value === 'subscription') legacy = true; } });
      if (legacy) throw new Error('Ambiguous composite translation domain: ' + name);
      return;
    }
    if (arg.node.value !== 'subscription') return;
    const start = Buffer.byteLength(source.slice(0, arg.node.start));
    const end = Buffer.byteLength(source.slice(0, arg.node.end));
    const quote = source[arg.node.start];
    edits.push([start, end, quote + 'ashbi-subscriptions' + quote]);
  },
});
process.stdout.write(JSON.stringify(edits));
