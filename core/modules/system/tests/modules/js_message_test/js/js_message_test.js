/**
 * @file
 *  Testing behavior for JSMessageTest.
 */

(({ behaviors, Message }, { testMessages }) => {
  // Message types.
  const indexes = {};
  testMessages.types.forEach((type) => {
    indexes[type] = [];
  });

  // Message storage.
  const messageObjects = {
    default: {
      zone: new Message(),
      indexes,
    },
    multiple: [],
  };
  // Ensure clear() can be called on a newly created message object.
  messageObjects.default.zone.clear();

  testMessages.selectors.filter(Boolean).forEach((selector) => {
    messageObjects[selector] = {
      zone: new Message(document.querySelector(selector)),
      indexes,
    };
  });

  /**
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Add click listeners that show and remove links with context and type.
   */
  behaviors.js_message_test = {
    attach() {
      function onAction(action, listener) {
        once(action, `[data-action="${action}"]`).forEach((button) => {
          button.addEventListener('click', listener);
        });
      }

      once('messages-details', '[data-drupal-messages-area]').forEach(
        (zone) => {
          zone.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            if (!button) {
              return;
            }
            const { type, action } = button.dataset;
            const area = zone.dataset.drupalMessagesArea || 'default';
            const message = messageObjects[area].zone;
            if (action === 'add') {
              messageObjects[area].indexes[type].push(
                message.add(
                  `This is a message of the type, ${type}. You be the judge of its importance.`,
                  { type },
                ),
              );
            } else if (action === 'remove') {
              message.remove(messageObjects[area].indexes[type].pop());
            }
          });
        },
      );
      onAction('add-multiple', () => {
        /**
         * Add several of different types to make sure message type doesn't
         * cause issues in the API.
         */
        [0, 1, 2, 3, 4, 5].forEach((i) => {
          messageObjects.multiple.push(
            messageObjects.default.zone.add(
              `This is message number ${i} of the type, ${
                testMessages.types[i % testMessages.types.length]
              }. You be the judge of its importance.`,
              { type: testMessages.types[i % testMessages.types.length] },
            ),
          );
        });
      });
      onAction('remove-multiple', () => {
        messageObjects.multiple.forEach((messageIndex) =>
          messageObjects.default.zone.remove(messageIndex),
        );
        messageObjects.multiple = [];
      });
      onAction('add-multiple-error', () => {
        // Use the same number of elements to facilitate things on the PHP side.
        [0, 1, 2, 3, 4, 5].forEach((i) =>
          messageObjects.default.zone.add(`Msg-${i}`, { type: 'error' }),
        );
        messageObjects.default.zone.add(
          `Msg-${testMessages.types.length * 2}`,
          { type: 'status' },
        );
      });
      onAction('remove-type', () => {
        document
          .querySelectorAll('[data-drupal-message-id^="error"]')
          .forEach((element) => {
            messageObjects.default.zone.remove(element.dataset.drupalMessageId);
          });
      });
      onAction('clear-all', () => {
        messageObjects.default.zone.clear();
      });
      onAction('id-no-status', () => {
        messageObjects.default.zone.add('Msg-id-no-status', {
          id: 'my-special-id',
        });
      });
    },
  };
})(Drupal, drupalSettings);
