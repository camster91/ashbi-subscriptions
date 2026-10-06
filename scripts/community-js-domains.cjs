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
  if (pattern.type === 'Identifier') scope.bindings.set(pattern.name, value);
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
    bind(node.id, target, globalNamespace(node.init, scope) ? { kind: 'namespace' } : { kind: 'other' });
  }
  children(node, child => collect(child, scope, functionScope, declarationKind));
}
collect(ast, null);
// Mutable alias reassignment is ambiguous; never silently treat it as i18n.
walk(ast, node => {
  if (node.type === 'AssignmentExpression' && node.left.type === 'Identifier') {
    const binding = resolve(node.left.name, scopes.get(node));
    if (binding) binding.kind = 'other';
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
