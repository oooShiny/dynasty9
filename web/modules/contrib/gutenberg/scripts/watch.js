const esbuild = require('esbuild');
const path = require('path');

async function watch() {
  const ctx = await esbuild.context({
    entryPoints: [path.resolve(__dirname, '../js/**/*.jsx')],
    minify: false,
    outdir: path.resolve(__dirname, '../js'),
    bundle: false,
    loader: {
      '.json': 'json',
    },
    banner: {
      js:
        '/**\n * Generated by a build script. Do not modify.\n * Check orginal .jsx file.\n */\n/* eslint-disable */\n',
    },
  });
  await ctx.watch();
  console.log('Watching...');
}
watch();