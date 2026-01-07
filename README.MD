[![Tests](https://github.com/wordpress/substack-importer/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/test.yml)
[![Coding Standards](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml)

# Substack Importer

The Substack Importer is a WordPress plugin that allows you to import a [Substack](https://substack.com) export into your WordPress site.

## Testing instructions

Testing the plugin locally is easiest done by using [wp-env](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/).

## Set up your local environment

The easiest way to run manual and unit tests for the Substack Importer is by using [wp-en](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/).
The instructions here assume you are using wp-env.

Steps:

1.  Install wp-env globally and add a .wp-env.json file. You can use the example below.

```json
{
	"core": null,
	"plugins": [".", "../wxr-generator", "WordPress/wordpress-importer"],
	"port": 1000,
	"testsPort": 1001,
	"config": {
		"WP_DEBUG_DISPLAY": true
	}
}
```

2. As the substack importer depends on the wxr-generator and wordpress-importer plugin, make sure you have added both of them to the `plugins` field. This field contains a list of plugins to be loaded and enabled.

3. Run `wp-env start` in the substack-importer plugin directory. This will fire up a couple of Docker containers that give you access to a working WordPress instance.

### Run unit tests

To run unit tests some composer dependencies need to be installed. We can run composer and install the dependencies by running th following command inside the substack-importer directory:

`wp-env run cli --env-cwd=wp-content/plugins/substack-importer composer install`

Unit tests can now be ran with the following command:

`wp-env run tests-cli --env-cwd=wp-content/plugins/substack-importer vendor/bin/phpunit --configuration=./phpunit.xml.dist`

## Publishing the plugin

When a new tag is pushed, the tag will be published to SVN.
