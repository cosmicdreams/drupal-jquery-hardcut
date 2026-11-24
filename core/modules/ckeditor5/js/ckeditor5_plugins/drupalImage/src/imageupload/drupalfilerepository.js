/* eslint-disable import/no-extraneous-dependencies */
/* cspell:ignore uploadurl drupalimageuploadadapter  */

import { Plugin, logWarning, FileRepository } from 'ckeditor5';
import DrupalImageUploadAdapter from './drupalimageuploadadapter';

/**
 * Provides a Drupal upload adapter.
 *
 * @private
 */
export default class DrupalFileRepository extends Plugin {
  /**
   * @inheritdoc
   */
  static get requires() {
    return [FileRepository];
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DrupalFileRepository';
  }

  /**
   * @inheritdoc
   */
  init() {
    const options = this.editor.config.get('drupalImageUpload');

    if (!options) {
      return;
    }

    if (!options.uploadUrl) {
      logWarning('simple-upload-adapter-missing-uploadurl');

      return;
    }

    const repository = this.editor.plugins.get(FileRepository);
    repository.createUploadAdapter = (loader) =>
      new DrupalImageUploadAdapter(loader, options);
    this.listenTo(this.editor, 'destroy', () => {
      for (const loader of repository.loaders) {
        if (loader.status === 'uploading' || loader.status === 'reading') {
          loader.abort();
        }
      }
    });
  }
}
