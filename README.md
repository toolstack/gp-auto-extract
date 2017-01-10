# GP Auto Extract

A plugin for [GlotPress](https://wordpress.org/plugins/glotpress) that adds an option to extract original strings from a remote source repo directly in to a GlotPress project.

To set it up, go to Settings->GP Auto Extract in WordPress. You'll see a list of your projects in GP along with two options:

1. Source Type
2. Setting

Each source type has the following settings associated with it:

1. None - Don't auto extract this project.
2. WordPress.org - the slug for the WordPress.org SVN repo to extract from (for example "gp-auto-extract" for this plugin).
3. GitHub - The user name and repo name on GitHub to extract from (for example "toolstack/gp-auto-extract").
4. Custom - a complete url to a ZIP file containing the source code to extract from.

Once the setting has be entered, you can save them with the button to the right and then run an extract which will update the originals in the given project from the source selected. 
