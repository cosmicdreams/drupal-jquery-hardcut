/**
 * @file
 * Detects JavaScript that acquires a jQuery reference.
 */

const jqueryNames = new Set(['jQuery', '$']);
const globalObjects = new Set([
  'window',
  'globalThis',
  'self',
  'global',
  'parent',
  'top',
  'opener',
  'frames',
]);
const documentNames = new Set(['document']);
const moduleLoaders = new Set(['require', 'define']);

function literalString(node) {
  if (node?.type === 'TemplateLiteral' && node.expressions.length === 0) {
    // cspell:ignore quasis
    return node.quasis[0].value.cooked;
  }
  return node?.type === 'Literal' ? node.value : undefined;
}

function propertyName(node, key = node.key) {
  return !node.computed && key?.type === 'Identifier'
    ? key.name
    : literalString(key);
}

export const jqueryPlugin = {
  rules: {
    'no-reference': {
      meta: {
        schema: [],
        messages: {
          reference: 'Use a native API in place of the jQuery reference.',
        },
      },

      create(context) {
        const { sourceCode } = context;

        function binding(node) {
          let scope = sourceCode.getScope(node);
          while (scope) {
            if (scope.set.has(node.name)) {
              return scope.set.get(node.name);
            }
            scope = scope.upper;
          }
          return undefined;
        }

        function isGlobal(node, names) {
          return (
            node?.type === 'Identifier' &&
            names.has(node.name) &&
            !binding(node)?.defs.length
          );
        }

        function memberKind(kind, name) {
          if (kind === 'document' && name === 'defaultView') {
            return 'window';
          }
          if (kind === 'window' || kind === 'frames') {
            if (name === 'document' || name === 'frames') {
              return name;
            }
            if (globalObjects.has(name)) {
              return 'window';
            }
          }
          return undefined;
        }

        function objectKind(node) {
          if (isGlobal(node, globalObjects)) {
            return node.name === 'frames' ? 'frames' : 'window';
          }
          if (isGlobal(node, documentNames)) {
            return 'document';
          }
          if (node?.type === 'ChainExpression') {
            return objectKind(node.expression);
          }
          if (node?.type === 'MemberExpression') {
            const kind = objectKind(node.object);
            const name = propertyName(node, node.property);
            // A frame index or name can itself resolve to another window.
            return (
              memberKind(kind, name) ??
              (kind === 'frames' && node.computed ? 'window' : undefined)
            );
          }
          if (node?.type !== 'Identifier') {
            return undefined;
          }
          const variable = binding(node);
          const definition = variable?.defs[0];
          // Follow single-assignment aliases, including destructuring.
          if (
            definition?.type !== 'Variable' ||
            variable.references.filter((reference) => reference.isWrite())
              .length !== 1 ||
            !(definition.node.init?.end < node.start)
          ) {
            return undefined;
          }
          let name = definition.name;
          const properties = [];
          while (name.parent.type === 'Property') {
            properties.unshift(propertyName(name.parent));
            name = name.parent.parent;
          }
          if (name === definition.node.id) {
            return properties.reduce(
              memberKind,
              objectKind(definition.node.init),
            );
          }
          return undefined;
        }

        function isGlobalObject(kind) {
          return kind === 'window' || kind === 'frames';
        }

        function report(node) {
          context.report({ node, messageId: 'reference' });
        }

        function checkSpecifier(node) {
          const specifier = literalString(node);
          if (
            typeof specifier === 'string' &&
            (/^(?:@[^/]+\/)?jquery/i.test(specifier) ||
              /(?:^|\/)(?:jquery(?:[.-]ui)?\/|jquery(?:[.-]ui|\.form)?(?:[.-]\d[\d.]*)?(?:[.-]min)?\.js(?:$|[?#]))/i.test(
                specifier,
              ))
          ) {
            report(node);
          }
        }

        function checkDestructuring(pattern, kind) {
          if (pattern?.type === 'ObjectPattern') {
            for (const property of pattern.properties) {
              const name = propertyName(property);
              if (isGlobalObject(kind) && jqueryNames.has(name)) {
                report(property);
              } else {
                const childKind = memberKind(kind, name);
                if (childKind) {
                  checkDestructuring(property.value, childKind);
                }
              }
            }
          }
        }

        return {
          MemberExpression(node) {
            const name = propertyName(node, node.property);
            // Literal computed keys can be checked. Resolving dynamic key
            // expressions is outside the scope of this rule.
            if (
              jqueryNames.has(name) &&
              isGlobalObject(objectKind(node.object))
            ) {
              report(node);
            }
          },
          VariableDeclarator(node) {
            if (node.id.type === 'ObjectPattern') {
              checkDestructuring(node.id, objectKind(node.init));
            }
          },
          'AssignmentExpression, AssignmentPattern'(node) {
            if (node.left.type === 'ObjectPattern') {
              checkDestructuring(node.left, objectKind(node.right));
            }
          },
          'ImportDeclaration, ExportNamedDeclaration, ExportAllDeclaration'(
            node,
          ) {
            checkSpecifier(node.source);
          },
          ImportExpression(node) {
            checkSpecifier(node.source);
          },
          CallExpression(node) {
            if (isGlobal(node.callee, moduleLoaders)) {
              // CommonJS loads a string. AMD can name dependencies in an array.
              for (const argument of node.arguments.slice(0, 2)) {
                if (argument.type === 'ArrayExpression') {
                  argument.elements.forEach(checkSpecifier);
                } else {
                  checkSpecifier(argument);
                }
              }
            }
          },
        };
      },
    },
  },
};
