/**
 * @file
 * Detects JavaScript that acquires a jQuery reference.
 */

const jqueryNames = new Set(['jQuery', '$']);
const globalObjects = new Set(['window', 'globalThis', 'self', 'global']);
const moduleLoaders = new Set(['require', 'define']);

function literalString(node) {
  if (node?.type === 'TemplateLiteral' && node.expressions.length === 0) {
    // cspell:ignore quasis
    return node.quasis[0].value.cooked;
  }
  return node?.type === 'Literal' ? node.value : undefined;
}

function propertyName(node) {
  return !node.computed && node.key?.type === 'Identifier'
    ? node.key.name
    : literalString(node.key);
}

export const jqueryRules = {
  'drupal-jquery/no-reference': 'error',
};

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
          if (node?.type !== 'Identifier') {
            return false;
          }
          const variable = binding(node);
          const definition = variable?.defs[0];
          // Follow simple local aliases of the browser global object. Requiring
          // a single initialization keeps reassignment and cycles out of scope.
          return (
            definition?.type === 'Variable' &&
            variable.references.filter((reference) => reference.isWrite())
              .length === 1 &&
            definition.node.id.type === 'Identifier' &&
            definition.node.init?.end < node.start &&
            isGlobalObject(definition.node.init)
          );
        }

        function report(node) {
          context.report({ node, messageId: 'reference' });
        }

        function checkSpecifier(node) {
          const specifier = literalString(node);
          if (
            typeof specifier === 'string' &&
            /(?:^|\/)(?:jquery(?:[.-]ui)?(?=\/|$)|jquery(?:[.-]ui|\.form)?(?:[.-]\d[\d.]*)?(?:[.-]min)?\.js(?:$|[?#]))/i.test(
              specifier,
            )
          ) {
            report(node);
          }
        }

        function checkDestructuring(pattern, source) {
          if (pattern?.type === 'ObjectPattern' && isGlobalObject(source)) {
            for (const property of pattern.properties) {
              if (jqueryNames.has(propertyName(property))) {
                report(property);
              }
            }
          }
        }

        return {
          'Program:exit'() {
            const global = sourceCode.scopeManager.globalScope;
            for (const reference of global.through) {
              if (jqueryNames.has(reference.identifier.name)) {
                report(reference.identifier);
              }
            }
            for (const name of jqueryNames) {
              const variable = global.set.get(name);
              if (variable && variable.defs.length === 0) {
                for (const reference of variable.references) {
                  report(reference.identifier);
                }
              }
            }
          },
          MemberExpression(node) {
            const name = node.computed
              ? literalString(node.property)
              : node.property.name;
            if (jqueryNames.has(name) && isGlobalObject(node.object)) {
              report(node);
            }
          },
          VariableDeclarator(node) {
            checkDestructuring(node.id, node.init);
          },
          'AssignmentExpression, AssignmentPattern'(node) {
            checkDestructuring(node.left, node.right);
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
