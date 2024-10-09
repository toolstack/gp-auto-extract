module.exports = function(grunt) {

grunt.initConfig({
  pkg: grunt.file.readJSON('package.json'),
  wp_readme_to_markdown: {
	convert: {
	    files: {
	      'readme.md': 'readme.txt'
	    },
	    options: {
			screenshot_url: 'assets/{screenshot}.png'
	    },
	},
  },
})

grunt.loadNpmTasks('grunt-wp-readme-to-markdown');

grunt.registerTask('default', ['wp_readme_to_markdown']);

};
