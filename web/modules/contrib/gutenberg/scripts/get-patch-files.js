/**
 * Get patch files list from package.json.
 */

const packageJson = require('../package.json');

const patchFiles = packageJson.config.gutenberg.patches || [];

// Iterate over patch files object and get file description and path.
// eslint-disable-next-line no-unused-vars
const patchFilesList = Object.entries(patchFiles).map(([description, file]) => {
  // eslint-disable-next-line no-console
  console.log(`${file}`);

  return file;
});

module.exports = patchFilesList;
