// AST ranges keep compiled and source JS byte-identical outside domain literals.
const fs = require('node:fs');
const { parse } = require('@babel/parser');
const source = fs.readFileSync(0, 'utf8');
const ast = parse(source, { sourceType: 'unambiguous', plugins: ['jsx'] });
const domains = { __: 1, _x: 2, _n: 3, _nx: 4 };
const scopes = new WeakMap();
function children(node, visit) {
  for (const [key, value] of Object.entries(node)) {
    if (['loc', 'tokens', 'comments', 'extra'].includes(key)) continue;
    if (Array.isArray(value)) value.forEach(child => child?.type && visit(child));
    else if (value?.type) visit(value);
  }
}
function walk(node, visit) {
  visit(node);
  children(node, child => walk(child, visit));
}
function path(node) {
  if (node?.type === 'Identifier') return node.name;
  if (node?.type === 'MemberExpression' && !node.computed) return path(node.object) + '.' + path(node.property);
  return '';
}
function bind(pattern, scope, value = { kind: 'other' }) {
  if (!pattern) return;
  if (pattern.type === 'Identifier') {
    scope.bindings.set(pattern.name, { ...value, declarations: (scope.bindings.get(pattern.name)?.declarations || 0) + 1 });
  }
  else if (pattern.type === 'RestElement') bind(pattern.argument, scope);
  else if (pattern.type === 'AssignmentPattern') bind(pattern.left, scope);
  else if (pattern.type === 'ObjectPattern') pattern.properties.forEach(p => bind(p.value || p.argument, scope));
  else if (pattern.type === 'ArrayPattern') pattern.elements.forEach(p => bind(p, scope));
}
function resolve(name, scope) {
  for (let current = scope; current; current = current.parent) {
    if (current.bindings.has(name)) return current.bindings.get(name);
  }
  return null;
}
function globalNamespace(node, scope) {
  const name = path(node);
  return ['wp.i18n', 'window.wp.i18n'].includes(name) && !resolve(name.split('.')[0], scope);
}
function collect(node, parent, functionScope = null, declarationKind = null) {
  let scope = parent;
  const isFunction = ['FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression', 'ObjectMethod', 'ClassMethod'].includes(node.type);
  if (node.type === 'FunctionDeclaration' || node.type === 'ClassDeclaration') bind(node.id, parent);
  if (node.type === 'Program' || node.type === 'BlockStatement' || node.type === 'CatchClause' || isFunction) {
    scope = { parent, bindings: new Map() };
    if (node.type === 'Program' || isFunction) functionScope = scope;
    if (isFunction) { bind(node.id, scope); node.params.forEach(p => bind(p, scope)); }
    if (node.type === 'CatchClause') bind(node.param, scope);
  }
  scopes.set(node, scope);
  if (node.type === 'ImportDeclaration') {
    for (const spec of node.specifiers) {
      let value = { kind: 'other' };
      if (node.source.value === '@wordpress/i18n') {
        if (spec.type === 'ImportSpecifier') value = { kind: 'function', name: spec.imported.name };
        if (spec.type === 'ImportNamespaceSpecifier') value = { kind: 'namespace' };
      }
      bind(spec.local, scope, value);
    }
  }
  if (node.type === 'VariableDeclaration') declarationKind = node.kind;
  if (node.type === 'VariableDeclarator') {
    const target = declarationKind === 'var' ? functionScope : scope;
    bind(node.id, target);
    declarations.push({ node, target, scope });
  }
  children(node, child => collect(child, scope, functionScope, declarationKind));
}
const declarations = [];
collect(ast, null);
// Classify only after every lexical/hoisted binding has been collected.
function identity(node, scope) {
  if (globalNamespace(node, scope)) return { kind: 'namespace' };
  if (node?.type === 'Identifier') return resolve(node.name, scope);
  if (node?.type === 'MemberExpression' && !node.computed &&
      identity(node.object, scope)?.kind === 'namespace' && Object.hasOwn(domains, node.property.name)) {
    return { kind: 'function', name: node.property.name };
  }
  return null;
}
// Resolve alias chains to a fixed point without allowing declaration order to
// determine lexical identity. Unsupported patterns fail before any edits emit.
let changed;
do {
  changed = false;
  for (const { node, target, scope } of declarations) {
    const value = identity(node.init, scope);
    if (!value || value.kind === 'other') continue;
    if (node.id.type !== 'Identifier') throw new Error('Unsupported translation alias pattern');
    const binding = target.bindings.get(node.id.name);
    if (binding.declarations > 1) throw new Error('Ambiguous translation alias redeclaration');
    if (binding.kind !== value.kind || binding.name !== value.name) {
      if (binding.kind !== 'other') throw new Error('Ambiguous translation alias redeclaration');
      target.bindings.set(node.id.name, { ...value, declarations: binding.declarations });
      changed = true;
    }
  }
} while (changed);
function namespaceIdentity(node, scope) {
  return globalNamespace(node, scope) ||
    (node?.type === 'Identifier' && resolve(node.name, scope)?.kind === 'namespace');
}
function affectsNamespace(node, scope) {
  if (!node) return false;
  if (node.type === 'ObjectPattern') return node.properties.some(p => affectsNamespace(p.value || p.argument, scope));
  if (node.type === 'ArrayPattern') return node.elements.some(p => affectsNamespace(p, scope));
  if (node.type === 'RestElement') return affectsNamespace(node.argument, scope);
  if (node.type === 'AssignmentPattern') return affectsNamespace(node.left, scope);
  if (node.type === 'Identifier') {
    const binding = resolve(node.name, scope);
    return (binding && binding.kind !== 'other') ||
      (['wp', 'window'].includes(node.name) && !resolve(node.name, scope));
  }
  if (node.type === 'MemberExpression') {
    const name = path(node);
    if (['wp.i18n', 'window.wp', 'window.wp.i18n'].includes(name) && !resolve(name.split('.')[0], scope)) return true;
    if (namespaceIdentity(node.object, scope)) return true;
    // Computed writes on a global ancestor could replace wp/i18n itself.
    if (node.computed && affectsNamespace(node.object, scope)) return true;
  }
  return false;
}
walk(ast, node => {
  const target = node.type === 'AssignmentExpression' ? node.left :
    node.type === 'UpdateExpression' || (node.type === 'UnaryExpression' && node.operator === 'delete') ? node.argument : null;
  if (target && affectsNamespace(target, scopes.get(node))) {
    throw new Error('Ambiguous write to translation namespace/member');
  }
});
// Reassignment may be genuine gettext again or custom business code: reject
// both instead of erasing identity and allowing the checker to certify omission.
walk(ast, node => {
  if (node.type === 'AssignmentExpression' && identity(node.right, scopes.get(node))?.kind &&
      identity(node.right, scopes.get(node)).kind !== 'other') {
    throw new Error('Unsupported assignment of translation alias');
  }
  const target = node.type === 'AssignmentExpression' ? node.left :
    node.type === 'UpdateExpression' ? node.argument : null;
  if (target?.type === 'Identifier') {
    const binding = resolve(target.name, scopes.get(node));
    if (binding && binding.kind !== 'other') throw new Error('Ambiguous translation alias reassignment');
  }
});
const edits = [];
walk(ast, node => {
  if (node.type !== 'CallExpression') return;
  const scope = scopes.get(node);
  let callee = node.callee;
  if (callee.type === 'SequenceExpression') callee = callee.expressions.at(-1);
  let name;
  if (callee.type === 'Identifier') {
    const binding = resolve(callee.name, scope);
    if (binding?.kind === 'function') name = binding.name;
  }
  if (callee.type === 'MemberExpression' && !callee.computed) {
    const binding = callee.object.type === 'Identifier' ? resolve(callee.object.name, scope) : null;
    if (binding?.kind === 'namespace' || globalNamespace(callee.object, scope)) name = callee.property.name;
  }
  if (!(name in domains)) return;
  const arg = node.arguments[domains[name]];
  if (!arg) return;
  if (arg.type !== 'StringLiteral') {
    let legacy = false;
    walk(arg, child => { if (child.type === 'StringLiteral' && child.value === 'subscription') legacy = true; });
    if (legacy) throw new Error('Ambiguous composite translation domain: ' + name);
    return;
  }
  if (arg.value !== 'subscription') return;
  const start = Buffer.byteLength(source.slice(0, arg.start));
  const end = Buffer.byteLength(source.slice(0, arg.end));
  const quote = source[arg.start];
  edits.push([start, end, quote + 'ashbi-subscriptions' + quote]);
});
process.stdout.write(JSON.stringify(edits));
