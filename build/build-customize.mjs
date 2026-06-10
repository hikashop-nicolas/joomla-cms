/**
 * Build the Customize-mode assets from build/media_source into media/.
 *
 * Compiles the customize engine + the customize-group plugins' sources (.es5.js -> .js + .min.js,
 * .css -> .css + .min.css) using the same handlers as the main Joomla build, but scoped to the
 * customize assets so it runs without populating the full media/vendor tree.
 *
 * Usage: node build/build-customize.mjs
 */
import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import recursive from 'recursive-readdir';

import { handleES5File } from './build-modules-js/javascript/handle-es5.mjs';
import { handleESMFile } from './build-modules-js/javascript/compile-to-es2017.mjs';
import { handleCssFile } from './build-modules-js/stylesheets/handle-css.mjs';

const sourceRoot = join(dirname(fileURLToPath(import.meta.url)), 'media_source');

const folders = [
  'customize',
  'plg_customize_content',
  'plg_customize_module',
  'plg_customize_position',
  'plg_customize_language',
  'plg_customize_view',
];

for (const folder of folders) {
  const files = await recursive(join(sourceRoot, folder));

  for (const file of files) {
    if (file.endsWith('.es6.js')) {
      await handleESMFile(file);
    } else if (file.endsWith('.es5.js')) {
      await handleES5File(file);
    } else if (file.endsWith('.css') && !file.endsWith('.min.css')) {
      await handleCssFile(file);
    } else if (file.endsWith('joomla.asset.json')) {
      // Copy the WebAsset manifest so WAM can register the assets by name (addExtensionRegistryFile).
      const dest = file.replace(`${sep}build${sep}media_source${sep}`, `${sep}media${sep}`);
      await mkdir(dirname(dest), { recursive: true });
      await copyFile(file, dest);
    }
  }
}

console.log('✅ Customize assets built into media/.');
