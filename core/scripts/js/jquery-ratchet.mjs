/**
 * @file
 * Detects JavaScript that acquires a jQuery reference.
 */

const jqueryNames = new Set(['jQuery', '$']);
const globalObjects = new Set(['window', 'globalThis', 'self', 'global']);
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

        function isGlobalObject(node) {
          if (isGlobal(node, globalObjects)) {
            return true;
          }
          if (node?.type === 'MemberExpression') {
            const name = propertyName(node, node.property);
            return (
              (globalObjects.has(name) && isGlobalObject(node.object)) ||
              (name === 'defaultView' && isGlobal(node.object, documentNames))
            );
          }
          if (node?.type !== 'Identifier') {
            return false;
          }
          const variable = binding(node);
          const definition = variable?.defs[0];
          // Follow simple local aliases of the browser global object. Requiring
          // a single initialization keeps reassignment and cycles out of scope.
          if (
            definition?.type !== 'Variable' ||
            variable.references.filter((reference) => reference.isWrite())
              .length !== 1 ||
            !(definition.node.init?.end < node.start)
          ) {
            return false;
          }
          let name = definition.name;
          // Each destructured property on the path must still be a global
          // object, e.g. const { window: { self: browser } } = globalThis.
          while (name.parent.type === 'Property') {
            if (!globalObjects.has(propertyName(name.parent))) {
              return false;
            }
            name = name.parent.parent;
          }
          return (
            name === definition.node.id && isGlobalObject(definition.node.init)
          );
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

        function checkDestructuring(pattern) {
          if (pattern?.type === 'ObjectPattern') {
            for (const property of pattern.properties) {
              if (jqueryNames.has(propertyName(property))) {
                report(property);
              } else if (globalObjects.has(propertyName(property))) {
                checkDestructuring(property.value);
              }
            }
          }
        }

        return {
          MemberExpression(node) {
            const name = propertyName(node, node.property);
            if (jqueryNames.has(name) && isGlobalObject(node.object)) {
              report(node);
            }
          },
          VariableDeclarator(node) {
            if (isGlobalObject(node.init)) {
              checkDestructuring(node.id);
            }
          },
          'AssignmentExpression, AssignmentPattern'(node) {
            if (isGlobalObject(node.right)) {
              checkDestructuring(node.left);
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
