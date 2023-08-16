const {src, dest, series} = require('gulp');
const babel = require('gulp-babel');
const uglify = require('gulp-uglify');
const cleanDir = require('gulp-clean-dir');

function build() {
  return src('js/*.js')
    .pipe(babel({
      presets: ['@babel/preset-env']
    }))
    .pipe(uglify())
    .pipe(cleanDir('./dist'))
    .pipe(dest('dist/js'))
};

exports.build = build;
exports.default = series(build);