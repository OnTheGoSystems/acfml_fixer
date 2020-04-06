# acfml_fixer
It fixes the issue introduced in ACFML 1.6.0 which caused inflation of wp_postmeta table

Commands:

wp acflml list -> creates a list of affected postmeta and save it to the affected.csv file in the plugin's directory
wp acflml clear -> clears postmeta affected by 1.6.0 bug
