/* eslint-disable import/no-extraneous-dependencies */
const sass = require('sass');

module.exports = (filePath, callback) => {
  const header = `/**\n * Don't edit this file. Generated from ${filePath}.\n **/\n`;

  const result = sass.compile(filePath, {
    outputStyle: 'expanded', // leave minification to Drupal.
    sourceMap: false,
  });

  result.css = header + result.css;

  callback(result);
};
