/**
 * @file
 * User behaviors.
 */

((Drupal) => {
  /**
   * An object containing CSS classes used for password widget.
   *
   * @type {object}
   * @prop {string} passwordParent - A CSS class for the parent element.
   * @prop {string} passwordsMatch - A CSS class indicating password match.
   * @prop {string} passwordsNotMatch - A CSS class indicating passwords
   *   doesn't match.
   * @prop {string} passwordWeak - A CSS class indicating weak password
   *   strength.
   * @prop {string} passwordFair - A CSS class indicating fair password
   *   strength.
   * @prop {string} passwordGood - A CSS class indicating good password
   *   strength.
   * @prop {string} passwordStrong - A CSS class indicating strong password
   *   strength.
   * @prop {string} widgetInitial - Initial CSS class that should be removed
   *   on a state change.
   * @prop {string} passwordEmpty - A CSS class indicating password has not
   *   been filled.
   * @prop {string} passwordFilled - A CSS class indicating password has
   *   been filled.
   * @prop {string} confirmEmpty - A CSS class indicating password
   *   confirmation has not been filled.
   * @prop {string} confirmFilled - A CSS class indicating password
   *   confirmation has been filled.
   */
  Drupal.user = {
    password: {
      css: {
        passwordParent: 'password-parent',
        passwordsMatch: 'ok',
        passwordsNotMatch: 'error',
        passwordWeak: 'is-weak',
        passwordFair: 'is-fair',
        passwordGood: 'is-good',
        passwordStrong: 'is-strong',
        widgetInitial: '',
        passwordEmpty: '',
        passwordFilled: '',
        confirmEmpty: '',
        confirmFilled: '',
      },
    },
  };

  /**
   * Attach handlers to evaluate the strength of any password fields and to
   * check that its confirmation is correct.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches password strength indicator and other relevant validation to
   *   password fields.
   */
  Drupal.behaviors.password = {
    attach(context, settings) {
      const css = Drupal.user.password.css;
      const config = settings.password;
      const classes = (...names) =>
        names.flatMap((name) => name?.split(/\s+/).filter(Boolean) || []);
      const template = document.createElement('template');
      const themeElement = (name, ...args) => {
        template.innerHTML = Drupal.theme(name, config, ...args);
        return template.content.firstElementChild;
      };

      once('password', 'input.js-password-field', context).forEach(
        (mainInput) => {
          const mainParent = mainInput.parentElement;
          const widget = mainInput.closest('.js-form-type-password-confirm');
          const confirm = widget.querySelector('input.js-password-confirm');
          const confirmParent = confirm.parentElement;
          const confirmMessage = themeElement('passwordConfirmMessage');
          const matchStatus = confirmMessage.querySelector(
            '[data-drupal-selector="password-match-status-text"]',
          );
          mainParent.classList.add(...classes(css.passwordParent));
          confirmParent.classList.add('confirm-parent');
          confirmParent.append(confirmMessage);

          const strengthClasses = classes(
            css.passwordWeak,
            css.passwordFair,
            css.passwordGood,
            css.passwordStrong,
          );
          const matchClasses = classes(
            css.passwordsMatch,
            css.passwordsNotMatch,
          );
          const widgetClasses = classes(
            css.widgetInitial,
            css.passwordEmpty,
            css.passwordFilled,
            css.confirmEmpty,
            css.confirmFilled,
          );
          let strengthBar;
          let strengthText;
          let suggestions;

          if (config.showStrengthIndicator) {
            const strength = themeElement('passwordStrength');
            strengthBar = strength.querySelector(
              '[data-drupal-selector="password-strength-indicator"]',
            );
            strengthText = strength.querySelector(
              '[data-drupal-selector="password-strength-text"]',
            );
            suggestions = themeElement('passwordSuggestions', []);
            suggestions.style.display = 'none';
            mainParent.append(strength);
            confirmParent.after(suggestions);
          }

          const applyWidgetClasses = () => {
            widget.classList.add(
              ...classes(
                mainInput.value ? css.passwordFilled : css.passwordEmpty,
                confirm.value ? css.confirmFilled : css.confirmEmpty,
              ),
            );
          };

          const passwordCheck = () => {
            if (config.showStrengthIndicator) {
              const result = Drupal.evaluatePasswordStrength(
                mainInput.value,
                config,
              );
              const nextSuggestions = themeElement(
                'passwordSuggestions',
                result.messageTips,
              );
              if (suggestions.innerHTML !== nextSuggestions.innerHTML) {
                nextSuggestions.style.display =
                  result.strength === 100 ? 'none' : '';
                suggestions.replaceWith(nextSuggestions);
                suggestions = nextSuggestions;
              }
              strengthBar.classList.remove(...strengthClasses);
              strengthBar.style.width = String(result.strength) + '%';
              strengthBar.classList.add(...classes(result.indicatorClass));
              strengthText.innerHTML = result.indicatorText;
            }

            if (confirm.value) {
              const matching = mainInput.value === confirm.value;
              const statusClasses = classes(
                matching ? css.passwordsMatch : css.passwordsNotMatch,
              );
              const message = matching
                ? config.confirmSuccess
                : config.confirmFailure;
              if (
                !statusClasses.length ||
                statusClasses.some(
                  (name) => !matchStatus.classList.contains(name),
                ) ||
                matchStatus.innerHTML !== message
              ) {
                matchStatus.classList.remove(...matchClasses);
                matchStatus.innerHTML = message;
                matchStatus.classList.add(...statusClasses);
              }
            }
            confirmMessage.style.visibility = confirm.value
              ? 'visible'
              : 'hidden';
            if (widgetClasses.length) {
              widget.classList.remove(...widgetClasses);
              applyWidgetClasses();
            }
          };

          if (widgetClasses.length) {
            applyWidgetClasses();
          }
          [mainInput, confirm].forEach((field) => {
            Drupal.formEvents.listen(field, 'input', passwordCheck);
          });
        },
      );
    },
  };

  /**
   * Evaluate the strength of a user's password.
   *
   * Returns the estimated strength and the relevant output message.
   *
   * @param {string} password
   *   The password to evaluate.
   * @param {object} passwordSettings
   *   A password settings object containing the text to display and the CSS
   *   classes for each strength level.
   *
   * @return {object}
   *   An object containing strength, message, indicatorText and indicatorClass.
   */
  Drupal.evaluatePasswordStrength = (password, passwordSettings) => {
    password = password.trim();
    let indicatorText;
    let indicatorClass;
    let weaknesses = 0;
    let strength = 100;
    const msg = [];

    const hasLowercase = /[a-z]/.test(password);
    const hasUppercase = /[A-Z]/.test(password);
    const hasNumbers = /[0-9]/.test(password);
    const hasPunctuation = /[^a-zA-Z0-9]/.test(password);

    // If there is a username edit box on the page, compare password to that,
    // otherwise use value from the database.
    const username =
      document.querySelector('input.username')?.value ??
      passwordSettings.username;

    // Lose 5 points for every character less than 12, plus a 30 point penalty.
    if (password.length < 12) {
      msg.push(passwordSettings.tooShort);
      strength -= (12 - password.length) * 5 + 30;
    }

    // Count weaknesses.
    if (!hasLowercase) {
      msg.push(passwordSettings.addLowerCase);
      weaknesses += 1;
    }
    if (!hasUppercase) {
      msg.push(passwordSettings.addUpperCase);
      weaknesses += 1;
    }
    if (!hasNumbers) {
      msg.push(passwordSettings.addNumbers);
      weaknesses += 1;
    }
    if (!hasPunctuation) {
      msg.push(passwordSettings.addPunctuation);
      weaknesses += 1;
    }

    // Apply penalty for each weakness (balanced against length penalty).
    switch (weaknesses) {
      case 1:
        strength -= 12.5;
        break;

      case 2:
        strength -= 25;
        break;

      case 3:
      case 4:
        strength -= 40;
        break;
    }

    // Check if password is the same as the username.
    if (password !== '' && password.toLowerCase() === username.toLowerCase()) {
      msg.push(passwordSettings.sameAsUsername);
      // Passwords the same as username are always very weak.
      strength = 5;
    }

    const cssClasses = Drupal.user.password.css;

    // Based on the strength, work out what text should be shown by the
    // password strength meter.
    if (strength < 60) {
      indicatorText = passwordSettings.weak;
      indicatorClass = cssClasses.passwordWeak;
    } else if (strength < 70) {
      indicatorText = passwordSettings.fair;
      indicatorClass = cssClasses.passwordFair;
    } else if (strength < 80) {
      indicatorText = passwordSettings.good;
      indicatorClass = cssClasses.passwordGood;
    } else if (strength <= 100) {
      indicatorText = passwordSettings.strong;
      indicatorClass = cssClasses.passwordStrong;
    }

    // Assemble the final message while keeping the original message array.
    const messageTips = msg;
    return {
      strength,
      indicatorText,
      indicatorClass,
      messageTips,
    };
  };
})(Drupal);
